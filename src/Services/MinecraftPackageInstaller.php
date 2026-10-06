<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftPackageInstaller
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftRiskGateService $riskGate,
    ) {}

    public function installModrinthPlugin(Server $server, MinecraftToolkitSetup $setup, string $projectId): MinecraftToolkitPackage
    {
        return $this->installModrinthPackage($server, $setup, $projectId);
    }

    public function installModrinthPackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $projectId,
        bool $allowAlreadyInstalled = false
    ): MinecraftToolkitPackage {
        return $this->installPackage($server, $setup, 'modrinth', $projectId, $allowAlreadyInstalled);
    }

    public function installCurseForgePackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $projectId,
        bool $allowAlreadyInstalled = false
    ): MinecraftToolkitPackage {
        $this->riskGate->assertAllowed('curseforge_usage', $server);

        return $this->installPackage($server, $setup, 'curseforge', $projectId, $allowAlreadyInstalled);
    }

    private function installPackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        string $projectId,
        bool $allowAlreadyInstalled = false
    ): MinecraftToolkitPackage {
        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.install.{$server->uuid}", 600);
        if (! $lock->get()) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_install_running'));
        }

        try {
            $this->state->assertOffline($server);

            return $this->performInstall($server, $setup, $source, $projectId, false, [], $allowAlreadyInstalled);
        } finally {
            $lock->release();
        }
    }

    /** @param string[] $stack */
    private function performInstall(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $source,
        string $projectId,
        bool $requiredDependency,
        array $stack,
        bool $allowAlreadyInstalled = false
    ): MinecraftToolkitPackage {
        $packageType = match ($setup->software) {
            'paper', 'purpur', 'folia' => 'plugin',
            'fabric', 'forge', 'neoforge' => 'mod',
            default => throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.software_no_packages')
            ),
        };
        $targetDirectory = $packageType === 'plugin' ? 'plugins' : 'mods';
        $packageLabel = $packageType === 'plugin' ? 'Plugin' : 'Mod';

        if ($packageType === 'mod' && $setup->loader_version === null) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.mod_server_no_loader'));
        }

        $alreadyInstalled = MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('source', $source)
            ->where('source_project_id', $projectId)
            ->where('managed', true)
            ->first();
        if ($alreadyInstalled instanceof MinecraftToolkitPackage) {
            if ($requiredDependency || $allowAlreadyInstalled) {
                if ($allowAlreadyInstalled && $alreadyInstalled->getAttribute('setup_id') !== $setup->id) {
                    $alreadyInstalled->forceFill(['setup_id' => $setup->id])->save();
                }

                return $alreadyInstalled;
            }

            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_already_managed', ['type' => $packageLabel]));
        }

        $stackKey = "$source:$projectId";
        if (in_array($stackKey, $stack, true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.dependency_recursive'));
        }
        $stack[] = $stackKey;

        try {
            $candidate = match ($source) {
                'modrinth' => $this->modrinth->installationCandidate($projectId, $setup),
                'curseforge' => $this->curseForge->installationCandidate($projectId, $setup),
                default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_source_unsupported')),
            };

            foreach ($this->requiredDependencies($candidate['dependencies'] ?? []) as $dependency) {
                $dependencyProjectId = $dependency['project_id'] ?? null;
                if (! is_string($dependencyProjectId) || $dependencyProjectId === '') {
                    throw new MinecraftToolkitException(
                        trans('minecrafttoolkit::strings.messages.dependency_unresolved_for', ['project' => $projectId])
                    );
                }

                $this->performInstall($server, $setup, $source, $dependencyProjectId, true, $stack, $allowAlreadyInstalled);
            }

            $project = $candidate['project'];
            $version = $candidate['version'];
            $file = $version['selected_file'];
            $fileName = $this->safeFileName((string) $file['filename']);
            $path = "/$targetDirectory/$fileName";
            $hashes = is_array($file['hashes'] ?? null) ? $file['hashes'] : [];

            // Wings responds with 404 when listing a directory that does not exist yet.
            // Create the package directory before checking for a conflicting target file.
            $this->files->makeDirectory($server, "/$targetDirectory");

            if ($this->files->exists($server, $path)) {
                if (! $allowAlreadyInstalled) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.file_exists_in_folder', ['file' => $fileName, 'folder' => $targetDirectory]));
                }

                // A queue interruption can happen after the verified atomic write but before
                // the managed package row is committed. Only adopt the file when it still
                // matches the source-provided checksum; unrelated user files remain untouched.
                $metadata = $this->files->inspectExistingJarWithMetadata($server, $path, $hashes);
                $this->assertDownloadedVersionMatchesCandidate(
                    $metadata['plugin_version'] ?? null,
                    (string) ($version['version_number'] ?? $version['name'] ?? '')
                );
            } else {
                $expectedVersion = (string) ($version['version_number'] ?? $version['name'] ?? '');
                $metadata = $this->files->downloadJarWithMetadata(
                    $server,
                    (string) $file['url'],
                    $path,
                    $hashes,
                    function (array $downloadedMetadata) use ($expectedVersion): void {
                        $this->assertDownloadedVersionMatchesCandidate(
                            $downloadedMetadata['plugin_version'] ?? null,
                            $expectedVersion
                        );
                    }
                );
            }

            $package = MinecraftToolkitPackage::query()->create([
                'server_uuid' => $server->uuid,
                'setup_id' => $setup->id,
                'source' => $source,
                'source_project_id' => $project['project_id'],
                'source_project_slug' => $project['slug'],
                'source_version_id' => $version['id'],
                'project_name' => $project['title'],
                'project_type' => $packageType,
                'package_type' => $requiredDependency ? 'dependency' : $packageType,
                'loader' => $setup->software,
                'minecraft_version' => $setup->minecraft_version,
                'version_number' => $version['version_number'] ?? $version['name'] ?? null,
                'file_name' => $fileName,
                'file_path' => $path,
                'download_url' => $file['url'],
                'sha1' => $metadata['sha1'] ?? ($file['hashes']['sha1'] ?? null),
                'sha512' => $metadata['sha512'] ?? ($file['hashes']['sha512'] ?? null),
                'side' => $project['server_side'],
                'dependencies_json' => $candidate['dependencies'],
                'is_required_dependency' => $requiredDependency,
                'is_system_package' => false,
                'managed' => true,
                'enabled' => true,
                'installed_by' => user()?->id,
                'installed_at' => now(),
            ]);

            $this->log($server, 'package_installed', 'success', trans('minecrafttoolkit::strings.messages.package_installed', ['type' => $packageLabel, 'name' => $project['title']]), [
                'project_id' => $project['project_id'],
                'version_id' => $version['id'],
                'file' => $fileName,
                'required_dependency' => $requiredDependency,
            ]);

            return $package;
        } catch (\Throwable $exception) {
            $message = $exception instanceof MinecraftToolkitException
                ? $exception->getMessage()
                : trans('minecrafttoolkit::strings.messages.package_install_failed', ['type' => $packageLabel]);
            $this->log($server, 'package_install_failed', 'error', $message, ['project_id' => $projectId]);
            Log::error('Minecraft Toolkit package installation failed', [
                'server_uuid' => $server->uuid,
                'project_id' => $projectId,
                'source' => $source,
                'exception' => $exception,
            ]);

            throw new MinecraftToolkitException($message, previous: $exception);
        }
    }

    private function assertDownloadedVersionMatchesCandidate(?string $downloadedVersion, string $expectedVersion): void
    {
        $downloadedVersion = trim((string) $downloadedVersion);
        $expectedVersion = trim($expectedVersion);
        if ($downloadedVersion === '' || $expectedVersion === '') {
            return;
        }

        if (! MinecraftPackageVersionNormalizer::equivalent($downloadedVersion, $expectedVersion)) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.downloaded_version_mismatch_install', ['downloaded' => $downloadedVersion, 'expected' => $expectedVersion])
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requiredDependencies(mixed $dependencies): array
    {
        if (! is_array($dependencies)) {
            return [];
        }

        return collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && is_string($dependency['project_id'] ?? null))
            ->values()
            ->all();
    }

    /** @param string[] $allowedExtensions */
    public function safeFileName(string $fileName, array $allowedExtensions = ['jar']): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = array_values(array_unique(array_map(
            fn (string $allowedExtension): string => strtolower(ltrim(trim($allowedExtension), '.')),
            $allowedExtensions
        )));

        if ($fileName === ''
            || strlen($fileName) > 200
            || str_starts_with($fileName, '.')
            || preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1
            || str_contains($fileName, '..')
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')
            || $extension === ''
            || ! in_array($extension, $allowedExtensions, true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_file_name_invalid'));
        }

        return $fileName;
    }

    /** @param array<string, mixed> $context */
    private function log(Server $server, string $action, string $level, string $message, array $context = []): void
    {
        try {
            MinecraftToolkitLog::query()->create([
                'server_uuid' => $server->uuid,
                'user_id' => user()?->id,
                'action' => $action,
                'level' => $level,
                'message' => $message,
                'context_json' => $context ?: null,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit could not persist a package log.', ['exception' => $exception]);
        }
    }
}
