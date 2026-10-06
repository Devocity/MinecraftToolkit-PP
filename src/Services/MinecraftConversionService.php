<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;

class MinecraftConversionService
{
    public function __construct(private readonly MinecraftSoftwareService $software, private readonly MinecraftServerFileService $files, private readonly MinecraftServerStateService $state) {}

    /** @return string[] */
    public function targets(MinecraftToolkitSetup $setup): array
    {
        return in_array($setup->software, ['paper', 'purpur', 'folia'], true)
            ? array_values(array_diff(['paper', 'purpur', 'folia'], [$setup->software])) : [];
    }

    public function convert(Server $server, MinecraftToolkitSetup $setup, string $target): MinecraftToolkitSetup
    {
        if (! in_array($target, $this->targets($setup), true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.conversion_unsupported'));
        }
        $this->state->assertOffline($server);
        $installation = $this->software->resolveInstallation($target, $setup->minecraft_version, null);
        $path = $setup->server_jar_path ?: '/server.jar';
        $backup = $this->files->backupIfPresent($server, $path);
        try {
            $metadata = $this->files->downloadJarWithMetadata($server, $installation['url'], $path, array_filter(['sha256' => $installation['sha256'] ?? null]));
        } catch (\Throwable $exception) {
            if ($backup && $this->files->exists($server, $backup)) {
                $this->files->move($server, $backup, $path);
            }
            throw $exception;
        }
        $old = $setup->software;
        $setup->update(['software' => $target, 'loader' => $target, 'loader_version' => null]);
        MinecraftToolkitPackage::query()->where('server_uuid', $server->uuid)->whereIn('package_type', ['server_jar', 'server_binary'])->update([
            'source' => $target === 'purpur' ? 'purpur' : 'papermc', 'source_project_id' => $target, 'project_name' => ucfirst($target), 'loader' => $target,
            'version_number' => $installation['version_id'] ?? $setup->minecraft_version, 'download_url' => $installation['url'], 'sha1' => $metadata['sha1'], 'sha512' => $metadata['sha512'], 'installed_at' => now(),
        ]);
        MinecraftToolkitLog::query()->create(['server_uuid' => $server->uuid, 'user_id' => user()?->id, 'action' => 'software_converted', 'level' => 'warning', 'message' => trans('minecrafttoolkit::strings.messages.software_converted', ['old' => $old, 'target' => $target]), 'context_json' => ['from' => $old, 'to' => $target, 'backup' => $backup]]);

        return $setup->refresh();
    }
}
