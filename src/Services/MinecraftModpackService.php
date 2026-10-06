<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitModpack;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ZipArchive;

class MinecraftModpackService
{
    public function __construct(
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftPackageInstaller $installer,
        private readonly CurseForgeService $curseForge,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function search(string $source, string $query = '', int $offset = 0, int $limit = 20): array
    {
        return match ($source) {
            'modrinth' => $this->searchModrinth($query, $offset, $limit),
            'curseforge' => $this->curseForge->searchModpacks($query, $offset, $limit),
            default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_source_invalid')),
        };
    }

    public function installPublic(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        string $projectId,
        string $mode = 'combine',
        ?string $versionId = null,
        ?int $installedBy = null
    ): MinecraftToolkitModpack {
        $candidate = match ($source) {
            'modrinth' => $this->modrinthCandidate($projectId, $versionId),
            'curseforge' => $this->curseForgeCandidate($projectId, $versionId),
            default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_source_invalid')),
        };

        $download = $this->files->downloadContents((string) $candidate['url'], ['mrpack', 'zip']);

        return $this->installArchive(
            $server,
            $setup,
            $source,
            $candidate,
            $download['contents'],
            $mode,
            $installedBy
        );
    }

    /** @return array<int, array{id: string, label: string}> */
    public function versions(string $source, string $projectId): array
    {
        if ($source === 'curseforge') {
            return collect($this->curseForge->modpackFiles($projectId))
                ->map(fn (array $file): array => [
                    'id' => (string) $file['id'],
                    'label' => (string) $file['display_name'],
                ])->all();
        }

        if ($source !== 'modrinth' || ! preg_match('/^[A-Za-z0-9_-]+$/', $projectId)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_source_or_id_invalid'));
        }

        $versions = Http::acceptJson()
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get("https://api.modrinth.com/v2/project/$projectId/version")
            ->throw()->json();

        return collect(is_array($versions) ? $versions : [])
            ->filter(fn (mixed $version): bool => is_array($version) && is_string($version['id'] ?? null))
            ->sortByDesc(fn (array $version): string => (string) ($version['date_published'] ?? ''))
            ->map(fn (array $version): array => [
                'id' => (string) $version['id'],
                'label' => (string) ($version['name'] ?? $version['version_number'] ?? $version['id']),
            ])->values()->all();
    }

    public function installUpload(
        Server $server,
        MinecraftToolkitSetup $setup,
        UploadedFile|string $file,
        string $mode = 'combine'
    ): MinecraftToolkitModpack {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! is_string($path) || ! is_file($path)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_upload_unreadable'));
        }

        $fileName = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($path);
        $fileName = $this->installer->safeFileName($fileName, ['mrpack', 'zip']);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['mrpack', 'zip'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_upload_type'));
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_upload_empty'));
        }

        return $this->installArchive($server, $setup, 'upload', [
            'project_id' => null,
            'version_id' => hash('sha1', $contents),
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'version_number' => null,
            'file_name' => $fileName,
            'url' => null,
        ], $contents, $mode);
    }

    public function activate(Server $server, int $modpackId): MinecraftToolkitModpack
    {
        $modpack = MinecraftToolkitModpack::query()
            ->whereKey($modpackId)
            ->where('server_uuid', $server->uuid)
            ->first();
        if (! $modpack instanceof MinecraftToolkitModpack) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_not_found'));
        }
        if ((bool) $modpack->active) {
            return $modpack;
        }

        $this->state->assertOffline($server);
        $this->archiveActive($server);
        $files = is_array($modpack->files_json) ? $modpack->files_json : [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $archivePath = is_string($file['archive_path'] ?? null) ? $file['archive_path'] : null;
            $targetPath = is_string($file['target_path'] ?? null) ? $file['target_path'] : null;
            if ($archivePath !== null && $targetPath !== null && $this->files->exists($server, $archivePath)) {
                $this->files->makeDirectory($server, dirname($targetPath));
                $this->files->move($server, $archivePath, $targetPath);
            }
        }

        MinecraftToolkitModpack::query()
            ->where('server_uuid', $server->uuid)
            ->update(['active' => false]);
        $modpack->forceFill(['active' => true])->save();
        $this->log($server, 'modpack_activated', trans('minecrafttoolkit::strings.messages.modpack_activated', ['name' => $modpack->name]), ['modpack_id' => $modpack->id]);

        return $modpack->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function searchModrinth(string $query, int $offset, int $limit): array
    {
        if (! (bool) config('minecrafttoolkit.modrinth_enabled', true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_disabled'));
        }

        $params = [
            'query' => trim($query),
            'limit' => max(1, min(50, $limit)),
            'offset' => max(0, $offset),
            'facets' => json_encode([['project_type:modpack']], JSON_THROW_ON_ERROR),
            'index' => 'downloads',
        ];
        $key = 'minecrafttoolkit.modrinth.modpacks.'.sha1(json_encode($params, JSON_THROW_ON_ERROR));
        $data = Cache::remember($key, now()->addMinutes(10), fn (): array => Http::acceptJson()
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get('https://api.modrinth.com/v2/search', $params)
            ->throw()
            ->json());

        return collect($data['hits'] ?? [])
            ->filter(fn (mixed $hit): bool => is_array($hit) && is_string($hit['project_id'] ?? null))
            ->map(fn (array $hit): array => [
                'project_id' => $hit['project_id'],
                'slug' => (string) ($hit['slug'] ?? $hit['project_id']),
                'title' => (string) ($hit['title'] ?? 'Unbekanntes Modpack'),
                'description' => (string) ($hit['description'] ?? ''),
                'icon_url' => is_string($hit['icon_url'] ?? null) ? $hit['icon_url'] : null,
                'project_url' => 'https://modrinth.com/modpack/'.(string) ($hit['slug'] ?? $hit['project_id']),
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'author' => (string) ($hit['author'] ?? ''),
                'updated_at' => is_string($hit['date_modified'] ?? null) ? $hit['date_modified'] : null,
                'source' => 'modrinth',
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function modrinthCandidate(string $projectId, ?string $versionId = null): array
    {
        $versions = Http::acceptJson()
            ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
            ->connectTimeout(5)
            ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
            ->get("https://api.modrinth.com/v2/project/$projectId/version")
            ->throw()
            ->json();
        $version = collect(is_array($versions) ? $versions : [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                && ($versionId === null || (string) ($candidate['id'] ?? '') === $versionId))
            ->sortByDesc(fn (array $candidate): string => (string) ($candidate['date_published'] ?? ''))
            ->first();
        if (! is_array($version)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_modpack_no_version'));
        }

        $file = collect($version['files'] ?? [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate) && is_string($candidate['url'] ?? null))
            ->first();
        if (! is_array($file)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_modpack_no_file'));
        }

        return [
            'project_id' => $projectId,
            'version_id' => (string) ($version['id'] ?? ''),
            'name' => (string) ($version['name'] ?? $version['version_number'] ?? 'Modrinth Modpack'),
            'version_number' => (string) ($version['version_number'] ?? ''),
            'file_name' => $this->installer->safeFileName(
                (string) ($file['filename'] ?? 'modpack.mrpack'),
                ['mrpack', 'zip']
            ),
            'url' => (string) $file['url'],
        ];
    }

    /** @return array<string, mixed> */
    private function curseForgeCandidate(string $projectId, ?string $versionId = null): array
    {
        $candidate = $this->curseForge->modpackFile($projectId, $versionId);
        $project = $candidate['project'];
        $file = $candidate['file'];

        return [
            'project_id' => $projectId,
            'version_id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($project['title'] ?? 'CurseForge Modpack'),
            'version_number' => (string) ($file['display_name'] ?? ''),
            'file_name' => $this->installer->safeFileName(
                (string) ($file['file_name'] ?? 'modpack.zip'),
                ['mrpack', 'zip']
            ),
            'url' => (string) ($file['url'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function installArchive(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        array $candidate,
        string $contents,
        string $mode,
        ?int $installedBy = null
    ): MinecraftToolkitModpack {
        $this->state->assertOffline($server);
        $parsed = $this->parseArchive($contents, (string) $candidate['file_name']);
        $modpack = MinecraftToolkitModpack::query()->create([
            'server_uuid' => $server->uuid,
            'setup_id' => $setup->id,
            'source' => $source,
            'source_project_id' => $candidate['project_id'] ?? null,
            'source_version_id' => $candidate['version_id'] ?? null,
            'name' => (string) ($parsed['name'] ?? $candidate['name']),
            'version_number' => (string) ($parsed['version_number'] ?? $candidate['version_number'] ?? ''),
            'file_name' => (string) $candidate['file_name'],
            'download_url' => $candidate['url'] ?? null,
            'minecraft_version' => $parsed['minecraft_version'] ?? $setup->minecraft_version,
            'loader' => $parsed['loader'] ?? $setup->loader,
            'loader_version' => $parsed['loader_version'] ?? $setup->loader_version,
            'install_path' => '/.minecraft-toolkit/modpacks/active',
            'manifest_json' => $parsed['manifest'],
            'files_json' => [],
            'active' => false,
            'installed_by' => $installedBy ?? user()?->id,
            'installed_at' => null,
        ]);

        try {
            if ($mode === 'replace') {
                $this->archiveActive($server);
            }

            $installedFiles = $this->writeModpackFiles($server, $parsed, $mode);
            DB::transaction(function () use ($server, $modpack, $installedFiles): void {
                MinecraftToolkitModpack::query()
                    ->where('server_uuid', $server->uuid)
                    ->where('id', '!=', $modpack->id)
                    ->update(['active' => false]);

                $modpack->forceFill([
                    'files_json' => $installedFiles,
                    'active' => true,
                    'installed_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $modpack->delete();

            throw $exception;
        }

        $this->log($server, 'modpack_installed', trans('minecrafttoolkit::strings.messages.modpack_installed', ['name' => $modpack->name]), [
            'modpack_id' => $modpack->id,
            'source' => $source,
            'mode' => $mode,
        ]);

        return $modpack;
    }

    /** @return array{name: string|null, version_number: string|null, minecraft_version: string|null, loader: string|null, loader_version: string|null, manifest: array<string, mixed>, files: array<int, array<string, mixed>>, overrides: array<string, string>} */
    private function parseArchive(string $contents, string $fileName): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'mctk-modpack-');
        if (! is_string($temp)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_temp_failed'));
        }
        file_put_contents($temp, $contents);

        $zip = new ZipArchive;
        if ($zip->open($temp) !== true) {
            @unlink($temp);
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modpack_archive_unreadable'));
        }

        try {
            $manifestRaw = $zip->getFromName('modrinth.index.json');
            $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
            if (! is_array($manifest)) {
                $manifestRaw = $zip->getFromName('manifest.json');
                $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : [];
            }
            if (! is_array($manifest)) {
                $manifest = [];
            }

            $overrides = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (! is_string($name) || str_ends_with($name, '/') || str_contains($name, '..')) {
                    continue;
                }
                $target = $this->archiveTargetPath($name);
                if (! $this->allowedOverridePath($target)) {
                    continue;
                }
                $data = $zip->getFromIndex($index);
                if (is_string($data) && $data !== '') {
                    $overrides[$target] = $data;
                }
            }
        } finally {
            $zip->close();
            @unlink($temp);
        }

        $dependencies = is_array($manifest['dependencies'] ?? null) ? $manifest['dependencies'] : [];
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $manifestFiles = $files;
        if (collect($files)->contains(fn (mixed $entry): bool => is_array($entry) && isset($entry['projectID'], $entry['fileID']))) {
            $manifestFiles = collect($files)
                ->filter(fn (mixed $entry): bool => is_array($entry) && isset($entry['projectID'], $entry['fileID']))
                ->map(fn (array $entry): array => [
                    'path' => 'mods/curseforge-'.(string) $entry['projectID'].'-'.(string) $entry['fileID'].'.jar',
                    'curseforge_project_id' => (string) $entry['projectID'],
                    'curseforge_file_id' => (string) $entry['fileID'],
                    'required' => (bool) ($entry['required'] ?? true),
                ])->values()->all();
        }
        $loader = collect(['fabric-loader', 'forge', 'neoforge', 'quilt-loader'])
            ->first(fn (string $key): bool => is_string($dependencies[$key] ?? null));

        return [
            'name' => is_string($manifest['name'] ?? null) ? $manifest['name'] : pathinfo($fileName, PATHINFO_FILENAME),
            'version_number' => is_string($manifest['versionId'] ?? null) ? $manifest['versionId'] : null,
            'minecraft_version' => is_string($dependencies['minecraft'] ?? null) ? $dependencies['minecraft'] : null,
            'loader' => $loader !== null ? str_replace('-loader', '', $loader) : null,
            'loader_version' => $loader !== null ? (string) $dependencies[$loader] : null,
            'manifest' => $manifest,
            'files' => $manifestFiles,
            'overrides' => $overrides,
        ];
    }

    private function archiveTargetPath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $overridePath = preg_replace('#^(?:server-)?overrides/#', '', $path);
        if (is_string($overridePath) && $overridePath !== $path) {
            return '/'.$overridePath;
        }

        $direct = '/'.$path;
        if ($this->allowedOverridePath($direct)) {
            return $direct;
        }

        $withoutRootFolder = preg_replace('#^[^/]+/#', '', $path);

        return is_string($withoutRootFolder) ? '/'.$withoutRootFolder : '';
    }

    /** @param array<string, mixed> $parsed
     * @return array<int, array<string, mixed>>
     */
    private function writeModpackFiles(Server $server, array $parsed, string $mode): array
    {
        $installed = [];
        $targetRoot = '/mods';
        $this->files->makeDirectory($server, $targetRoot);

        if ($mode === 'replace') {
            $this->archiveDirectoryFiles($server, $targetRoot, 'manual-replace');
        }

        foreach ($parsed['files'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['path'] ?? null)) {
                continue;
            }
            $downloads = is_array($entry['downloads'] ?? null) ? $entry['downloads'] : [];
            $url = collect($downloads)->first(fn (mixed $download): bool => is_string($download));
            $target = '/'.ltrim((string) $entry['path'], '/');
            $legacyTarget = $target;
            if (is_string($entry['curseforge_project_id'] ?? null)
                && is_string($entry['curseforge_file_id'] ?? null)) {
                $curseForgeFile = $this->curseForge->modFile(
                    $entry['curseforge_project_id'],
                    $entry['curseforge_file_id']
                );
                $url = $curseForgeFile['url'];
                $target = '/mods/'.$this->installer->safeFileName($curseForgeFile['file_name']);
            }
            if (! is_string($url)) {
                continue;
            }
            if (! $this->allowedOverridePath($target)) {
                continue;
            }
            $download = $this->files->downloadContents($url, ['jar', 'zip', 'disabled']);
            $this->files->makeDirectory($server, dirname($target));
            $backupPath = $this->files->writeAtomically($server, $target, $download['contents']);
            $legacyArchivePath = null;
            if ($legacyTarget !== $target && $this->files->exists($server, $legacyTarget)) {
                $legacyArchivePath = $this->files->backupIfPresent($server, $legacyTarget);
            }
            $installed[] = [
                'target_path' => $target,
                'archive_path' => $backupPath,
                'legacy_archive_path' => $legacyArchivePath,
                'sha1' => $download['sha1'],
                'sha512' => $download['sha512'],
                'source_url' => $url,
            ];
        }

        foreach ($parsed['overrides'] ?? [] as $target => $contents) {
            if (! is_string($target) || ! is_string($contents) || ! $this->allowedOverridePath($target)) {
                continue;
            }
            $this->files->makeDirectory($server, dirname($target));
            $backupPath = $this->files->writeAtomically($server, $target, $contents);
            $installed[] = [
                'target_path' => $target,
                'archive_path' => $backupPath,
                'sha1' => hash('sha1', $contents),
                'sha512' => hash('sha512', $contents),
                'source_url' => 'override',
            ];
        }

        return $installed;
    }

    private function archiveActive(Server $server): void
    {
        $active = MinecraftToolkitModpack::query()
            ->where('server_uuid', $server->uuid)
            ->where('active', true)
            ->first();
        if (! $active instanceof MinecraftToolkitModpack) {
            return;
        }

        $archiveRoot = '/.minecraft-toolkit/modpacks/archives/'.now()->format('Y-m-d-H-i-s').'-'.$active->id;
        $this->files->makeDirectory($server, $archiveRoot);
        $files = is_array($active->files_json) ? $active->files_json : [];
        foreach ($files as &$file) {
            if (! is_array($file) || ! is_string($file['target_path'] ?? null)) {
                continue;
            }
            $target = $file['target_path'];
            if (! $this->files->exists($server, $target)) {
                continue;
            }
            $archivePath = $archiveRoot.'/'.ltrim((string) $target, '/');
            $this->files->makeDirectory($server, dirname($archivePath));
            $this->files->move($server, $target, $archivePath);
            $file['archive_path'] = $archivePath;
        }
        unset($file);

        $active->forceFill([
            'active' => false,
            'archive_path' => $archiveRoot,
            'files_json' => $files,
        ])->save();
    }

    private function archiveDirectoryFiles(Server $server, string $directory, string $label): void
    {
        $archiveRoot = '/.minecraft-toolkit/modpacks/replaced/'.now()->format('Y-m-d-H-i-s').'-'.$label;
        $this->files->makeDirectory($server, $archiveRoot);
        foreach ($this->files->listDirectory($server, $directory) as $file) {
            $name = is_string($file['name'] ?? null) ? $file['name'] : '';
            if ($name === '' || ($file['directory'] ?? false)) {
                continue;
            }
            $this->files->move($server, "$directory/$name", "$archiveRoot/$name");
        }
    }

    private function allowedOverridePath(string $path): bool
    {
        $path = '/'.ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return str_starts_with($path, '/mods/')
            || str_starts_with($path, '/config/')
            || str_starts_with($path, '/defaultconfigs/')
            || str_starts_with($path, '/kubejs/')
            || str_starts_with($path, '/resourcepacks/')
            || str_starts_with($path, '/datapacks/');
    }

    /** @param array<string, mixed> $context */
    private function log(Server $server, string $action, string $message, array $context = []): void
    {
        if (! Schema::hasTable('minecraft_toolkit_logs')) {
            return;
        }

        MinecraftToolkitLog::query()->create([
            'server_uuid' => $server->uuid,
            'user_id' => user()?->id,
            'action' => $action,
            'level' => 'info',
            'message' => $message,
            'context_json' => $context ?: null,
        ]);
    }
}
