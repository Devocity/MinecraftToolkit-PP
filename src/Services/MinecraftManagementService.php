<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;

class MinecraftManagementService
{
    public function __construct(
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftPropertiesService $properties,
    ) {}

    /** @return array<string, string> */
    public function accessFiles(Server $server, string $edition): array
    {
        $paths = $edition === 'bedrock'
            ? ['/allowlist.json', '/permissions.json']
            : ['/whitelist.json', '/ops.json', '/banned-players.json', '/banned-ips.json'];

        $result = [];
        foreach ($paths as $path) {
            try {
                $result[ltrim($path, '/')] = $this->files->read($server, $path, 1048576);
            } catch (\Throwable) {
                $result[ltrim($path, '/')] = '[]';
            }
        }

        return $result;
    }

    /** @return array{name: string, seed: string} */
    public function worldInfo(Server $server, string $fallbackName): array
    {
        try {
            $values = $this->properties->parse($this->files->read($server, '/server.properties', 1048576));
        } catch (\Throwable) {
            $values = [];
        }

        return ['name' => (string) ($values['level-name'] ?? $fallbackName), 'seed' => (string) ($values['level-seed'] ?? '')];
    }

    /** @param array<string, string> $documents */
    public function saveAccessFiles(Server $server, array $documents): void
    {
        $this->state->assertOffline($server);
        $allowed = ['whitelist.json', 'ops.json', 'banned-players.json', 'banned-ips.json', 'allowlist.json', 'permissions.json'];
        foreach ($documents as $name => $json) {
            if (! in_array($name, $allowed, true)) {
                continue;
            }
            $decoded = json_decode($json, true);
            if (! is_array($decoded) || array_is_list($decoded) === false) {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.json_array_required', ['name' => $name]));
            }
            $this->files->writeAtomically($server, '/'.$name, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    public function renameWorld(Server $server, string $oldName, string $newName): void
    {
        $this->state->assertOffline($server);
        $oldName = $this->safeWorldName($oldName);
        $newName = $this->safeWorldName($newName);
        if (! $this->files->exists($server, '/'.$oldName)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_old_not_found'));
        }
        if ($this->files->exists($server, '/'.$newName)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_new_exists'));
        }
        $this->files->backupIfPresent($server, '/server.properties');
        $this->files->move($server, '/'.$oldName, '/'.$newName);
        $raw = $this->files->read($server, '/server.properties', 1048576);
        $this->files->writeAtomically($server, '/server.properties', $this->properties->patch($raw, ['level-name' => $newName]));
    }

    public function installDatapack(Server $server, string $worldName, string $fileName, string $contents): void
    {
        $this->state->assertOffline($server);
        if (! preg_match('/^[A-Za-z0-9._-]+\.zip$/i', $fileName) || ! str_starts_with($contents, "PK\x03\x04")) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.datapack_invalid_zip'));
        }
        if (class_exists(\ZipArchive::class)) {
            $tmp = tempnam(sys_get_temp_dir(), 'mctk-datapack-');
            file_put_contents((string) $tmp, $contents);
            try {
                $zip = new \ZipArchive;
                if ($zip->open((string) $tmp) !== true || $zip->locateName('pack.mcmeta', \ZipArchive::FL_NODIR) === false) {
                    throw new MinecraftToolkitException('Im Datapack fehlt pack.mcmeta.');
                }
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $entry = (string) $zip->getNameIndex($index);
                    if (str_contains($entry, '../') || str_starts_with($entry, '/')) {
                        throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.datapack_unsafe_path'));
                    }
                }
                $zip->close();
            } finally {
                @unlink((string) $tmp);
            }
        }
        $directory = '/'.$this->safeWorldName($worldName).'/datapacks';
        $this->files->makeDirectory($server, $directory);
        $this->files->writeAtomically($server, $directory.'/'.$fileName, $contents);
    }

    public function configureResourcePack(Server $server, string $url, bool $required, string $prompt = ''): string
    {
        $download = $this->files->downloadContents($url, ['zip']);
        if (! str_starts_with($download['contents'], "PK\x03\x04")) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.resourcepack_invalid_zip'));
        }
        $raw = $this->files->read($server, '/server.properties', 1048576);
        $this->files->writeAtomically($server, '/server.properties', $this->properties->patch($raw, [
            'resource-pack' => $url,
            'resource-pack-sha1' => $download['sha1'],
            'require-resource-pack' => $required,
            'resource-pack-prompt' => $prompt,
        ]));

        return $download['sha1'];
    }

    public function writeServerIcon(Server $server, string $contents): void
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.gd_required'));
        }
        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.image_invalid'));
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $size = min($width, $height);
        $target = imagecreatetruecolor(64, 64);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, (int) (($width - $size) / 2), (int) (($height - $size) / 2), 64, 64, $size, $size);
        ob_start();
        imagepng($target);
        $png = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);
        $this->files->writeAtomically($server, '/server-icon.png', $png);
    }

    public function applyPerformancePreset(Server $server, string $preset): void
    {
        $this->state->assertOffline($server);
        $values = match ($preset) {
            'performance' => ['view-distance' => 8, 'simulation-distance' => 6, 'network-compression-threshold' => 256],
            'high_performance' => ['view-distance' => 6, 'simulation-distance' => 4, 'network-compression-threshold' => 512],
            'quality' => ['view-distance' => 12, 'simulation-distance' => 10, 'network-compression-threshold' => 128],
            default => throw new MinecraftToolkitException('Unbekanntes Performance-Preset.'),
        };
        $raw = $this->files->read($server, '/server.properties', 1048576);
        $this->files->writeAtomically($server, '/server.properties', $this->properties->patch($raw, $values));

        $paper = "# Managed by Minecraft Toolkit\nchunk-loading:\n  autoconfig-send-distance: true\n  player-max-concurrent-loads: ".($preset === 'high_performance' ? '4' : '8')."\n";
        $this->files->makeDirectory($server, '/config');
        $this->files->writeAtomically($server, '/config/minecraft-toolkit-performance.yml', $paper);
    }

    /** @return array<int, array{label: string, ok: bool, detail: string}> */
    public function geyserDiagnostics(Server $server, int $bedrockPort): array
    {
        $checks = [];
        foreach (['/plugins/Geyser-Spigot.jar', '/plugins/floodgate-spigot.jar', '/plugins/Geyser-Spigot/config.yml'] as $path) {
            $checks[] = ['label' => $path, 'ok' => $this->files->exists($server, $path), 'detail' => $this->files->exists($server, $path) ? 'Gefunden' : 'Fehlt'];
        }
        try {
            $config = $this->files->read($server, '/plugins/Geyser-Spigot/config.yml', 1048576);
        } catch (\Throwable) {
            $config = '';
        }
        $checks[] = ['label' => 'Bedrock-Port', 'ok' => preg_match('/port:\s*'.preg_quote((string) $bedrockPort, '/').'\b/', $config) === 1, 'detail' => (string) $bedrockPort];
        $checks[] = ['label' => 'Floodgate-Authentifizierung', 'ok' => preg_match('/auth-type:\s*floodgate/i', $config) === 1, 'detail' => 'auth-type: floodgate'];
        try {
            $log = $this->files->read($server, '/logs/latest.log', 2097152);
        } catch (\Throwable) {
            $log = '';
        }
        $checks[] = ['label' => 'Letzter Start', 'ok' => stripos($log, 'Geyser') !== false && stripos($log, 'Floodgate') !== false, 'detail' => 'Geyser/Floodgate im Log'];

        return $checks;
    }

    public function backupWorld(Server $server, string $worldName): string
    {
        $this->state->assertOffline($server);
        $worldName = $this->safeWorldName($worldName);
        if (! $this->files->exists($server, '/'.$worldName)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_not_found'));
        }
        $directory = '/.minecraft-toolkit/world-backups';
        $this->files->makeDirectory($server, $directory);
        $name = now()->format('Y-m-d-H-i-s').'-'.$worldName;
        $this->files->compress($server, '/', [$worldName], $name, 'tar.gz');
        $source = '/'.$name.'.tar.gz';
        $target = $directory.'/'.$name.'.tar.gz';
        if (! $this->files->exists($server, $source)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_archive_unconfirmed'));
        }
        $this->files->move($server, $source, $target);

        return $target;
    }

    /** @return array<int, string> */
    public function worldBackups(Server $server): array
    {
        try {
            return collect($this->files->listDirectory($server, '/.minecraft-toolkit/world-backups'))->filter(fn (array $file): bool => (bool) ($file['is_file'] ?? true))->pluck('name')->filter(fn (mixed $name): bool => is_string($name) && str_ends_with($name, '.tar.gz'))->sortDesc()->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function restoreWorld(Server $server, string $archiveName, string $worldName): void
    {
        $this->state->assertOffline($server);
        $worldName = $this->safeWorldName($worldName);
        if (! preg_match('/^[0-9-]+-[A-Za-z0-9._-]+\.tar\.gz$/', $archiveName) || ! in_array($archiveName, $this->worldBackups($server), true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_backup_invalid'));
        }
        $root = '/.minecraft-toolkit/world-backups';
        $this->files->decompress($server, $root, $archiveName);
        $extractedName = preg_replace('/^[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{2}-/', '', substr($archiveName, 0, -7));
        $extracted = $root.'/'.$extractedName;
        if (! $this->files->exists($server, $extracted)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_extracted_not_found'));
        }
        if ($this->files->exists($server, '/'.$worldName)) {
            $this->files->move($server, '/'.$worldName, $root.'/replaced-'.now()->format('Y-m-d-H-i-s').'-'.$worldName);
        }
        $this->files->move($server, $extracted, '/'.$worldName);
    }

    private function safeWorldName(string $name): string
    {
        $name = trim($name);
        if (! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) || in_array($name, ['.', '..'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.world_name_invalid'));
        }

        return $name;
    }
}
