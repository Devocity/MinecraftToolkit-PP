<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MinecraftSetupService
{
    public function __construct(
        private readonly MinecraftSoftwareService $software,
        private readonly MinecraftPropertiesService $properties,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftCrossplayService $crossplay,
        private readonly MinecraftPackageInstaller $packageInstaller,
        private readonly MinecraftRiskGateService $riskGate,
    ) {}

    /** @param array<string, mixed> $data */
    public function setup(Server $server, array $data, mixed $icon = null, ?int $userId = null): MinecraftToolkitSetup
    {
        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.setup.{$server->uuid}", 600);
        if (! $lock->get()) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.setup_running'));
        }

        try {
            return $this->performSetup($server, $data, $icon, $userId);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $data */
    private function performSetup(Server $server, array $data, mixed $icon, ?int $userId): MinecraftToolkitSetup
    {
        if (! $server->allocation) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.no_primary_allocation')
            );
        }

        $this->state->assertOffline($server);
        $download = $this->software->resolveInstallation(
            (string) $data['software'],
            (string) $data['minecraft_version'],
            is_string($data['loader_version'] ?? null) ? $data['loader_version'] : null
        );
        $this->assertStartupPermission($server, $download['startup']);

        $setup = MinecraftToolkitSetup::query()->updateOrCreate(
            ['server_uuid' => $server->uuid],
            $this->setupAttributes($server, $data, $userId) + [
                'setup_status' => 'installing',
                'setup_started_at' => now(),
                'setup_completed_at' => null,
                'last_error' => null,
            ]
        );
        $this->log($server, 'setup_started', 'info', trans('minecrafttoolkit::strings.messages.setup_started'));

        try {
            $isBedrock = (string) $data['software'] === 'bedrock';
            if ($isBedrock) {
                $artifactMetadata = $this->files->downloadFile($server, $download['url'], $download['file_name'], ['zip']);
                $this->files->writeAtomically(
                    $server,
                    '/server.properties',
                    $this->properties->generateBedrock($data, (int) $server->allocation->port)
                );
            } else {
                if (is_string($download['sha256'])) {
                    $artifactMetadata = $this->files->downloadJarWithMetadata(
                        $server,
                        $download['url'],
                        '/'.$download['file_name'],
                        ['sha256' => $download['sha256']]
                    );
                } else {
                    $artifactMetadata = $this->files->downloadJarWithMetadata(
                        $server,
                        $download['url'],
                        '/'.$download['file_name']
                    );
                }
                $this->files->writeAtomically($server, '/eula.txt', "eula=true\n");
                $this->files->writeAtomically(
                    $server,
                    '/server.properties',
                    $this->properties->generateJava($data, (int) $server->allocation->port)
                );
            }

            $iconInstalled = $isBedrock ? false : $this->writeIcon($server, $icon);
            $crossplayConfigured = null;
            if (! $isBedrock && (bool) ($data['crossplay_enabled'] ?? false)) {
                $allocationId = (int) ($data['bedrock_allocation_id'] ?? 0);
                $crossplayConfigured = $this->crossplay->install($server, $setup, $allocationId);
            }
            if (is_string($download['startup'])) {
                $server->forceFill(['startup' => $download['startup']])->saveOrFail();
            }

            $setup->forceFill([
                'server_jar_path' => (! $download['installer'] && ! $isBedrock) ? '/'.$download['file_name'] : null,
                'server_binary_path' => $isBedrock ? '/bedrock_server' : ($download['installer'] ? '/run.sh' : null),
                'icon_path' => $iconInstalled ? '/server-icon.png' : null,
                'setup_status' => 'installing',
                'setup_completed_at' => null,
                'last_error' => null,
            ])->save();

            MinecraftToolkitPackage::query()->updateOrCreate(
                [
                    'server_uuid' => $server->uuid,
                    'package_type' => $isBedrock ? 'server_binary' : 'server_jar',
                ],
                [
                    'setup_id' => $setup->id,
                    'source' => $download['source'],
                    'source_project_id' => (string) $data['software'],
                    'source_version_id' => $download['version_id'],
                    'project_name' => $isBedrock ? 'Vanilla Bedrock' : ucfirst((string) $data['software']),
                    'project_type' => $isBedrock ? 'bedrock_server' : 'server',
                    'loader' => $setup->loader,
                    'minecraft_version' => (string) $data['minecraft_version'],
                    'version_number' => $download['version_id'],
                    'file_name' => $download['file_name'],
                    'file_path' => '/'.$download['file_name'],
                    'download_url' => $download['url'],
                    'sha1' => $artifactMetadata['sha1'] ?? null,
                    'sha512' => $artifactMetadata['sha512'] ?? null,
                    'dependencies_json' => is_string($download['sha256'])
                        ? ['sha256' => $download['sha256']]
                        : null,
                    'is_system_package' => true,
                    'managed' => true,
                    'enabled' => true,
                    'installed_by' => $userId ?? user()?->id,
                    'installed_at' => now(),
                ]
            );

            $this->installSelectedSetupPackages($server, $setup->refresh(), $data['setup_package_ids'] ?? []);

            $this->log($server, 'setup_completed', 'success', trans('minecrafttoolkit::strings.messages.setup_completed'));
            if ($download['installer']) {
                $this->log(
                    $server,
                    'loader_install_pending',
                    'info',
                    trans('minecrafttoolkit::strings.messages.loader_installer_first_start')
                );
            }
            if ($crossplayConfigured === false) {
                $this->log(
                    $server,
                    'crossplay_config_pending',
                    'warning',
                    trans('minecrafttoolkit::strings.messages.crossplay_apply_after_start')
                );
            }

            return $setup->refresh();
        } catch (\Throwable $exception) {
            $message = $exception instanceof MinecraftToolkitException
                ? $exception->getMessage()
                : trans('minecrafttoolkit::strings.messages.setup_failed');

            $setup->forceFill(['setup_status' => 'failed', 'last_error' => $message])->save();
            $this->log($server, 'setup_failed', 'error', $message, ['exception' => $exception::class]);
            Log::error('Minecraft Toolkit setup failed', [
                'server_uuid' => $server->uuid,
                'exception' => $exception,
            ]);

            throw new MinecraftToolkitException($message, previous: $exception);
        }
    }

    /** @param array<string, mixed> $data */
    private function setupAttributes(Server $server, array $data, ?int $userId): array
    {
        return [
            'server_id' => $server->id,
            'user_id' => $userId ?? user()?->id,
            'edition' => $data['software'] === 'bedrock' ? 'bedrock' : 'java',
            'software' => $data['software'],
            'minecraft_version' => $data['minecraft_version'],
            'loader' => in_array($data['software'], ['fabric', 'forge', 'neoforge'], true)
                ? $data['software']
                : null,
            'loader_version' => $data['loader_version'] ?? null,
            'motd' => $data['motd'],
            'level_name' => $data['level_name'],
            'max_players' => (int) $data['max_players'],
            'gamemode' => $data['gamemode'],
            'difficulty' => $data['difficulty'],
            'online_mode' => (bool) $data['online_mode'],
            'whitelist' => (bool) $data['whitelist'],
            'pvp' => (bool) $data['pvp'],
            'allow_nether' => (bool) $data['allow_nether'],
            'spawn_protection' => (int) $data['spawn_protection'],
            'view_distance' => (int) $data['view_distance'],
            'simulation_distance' => (int) $data['simulation_distance'],
            'enable_command_block' => (bool) $data['enable_command_block'],
            'allow_flight' => (bool) $data['allow_flight'],
            'enable_query' => (bool) $data['enable_query'],
            'enable_rcon' => (bool) $data['enable_rcon'],
            'primary_allocation_ip' => $server->allocation->ip,
            'primary_allocation_port' => $server->allocation->port,
            'crossplay_enabled' => false,
            'geyser_enabled' => false,
            'floodgate_enabled' => false,
        ];
    }

    private function installSelectedSetupPackages(Server $server, MinecraftToolkitSetup $setup, mixed $selectedPackages): void
    {
        if (! is_array($selectedPackages) || $selectedPackages === []) {
            return;
        }

        $failures = [];
        foreach (array_values(array_unique(array_filter($selectedPackages, 'is_string'))) as $selectedPackage) {
            if (! str_contains($selectedPackage, ':')) {
                continue;
            }

            [$source, $projectId] = explode(':', $selectedPackage, 2);
            try {
                match ($source) {
                    'modrinth' => $this->packageInstaller->installModrinthPackage($server, $setup, $projectId, true),
                    'curseforge' => $this->packageInstaller->installCurseForgePackage($server, $setup, $projectId, true),
                    default => throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_source_invalid')),
                };
            } catch (\Throwable $exception) {
                $failures[] = "$source:$projectId - ".($exception instanceof MinecraftToolkitException
                    ? $exception->getMessage()
                    : 'Technischer Fehler');
                $this->log($server, 'setup_package_install_failed', 'warning', end($failures) ?: trans('minecrafttoolkit::strings.messages.package_install_failed_generic'), [
                    'selected_package' => $selectedPackage,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($failures !== []) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.selected_packages_failed', ['failures' => implode('; ', $failures)])
            );
        }
    }

    private function writeIcon(Server $server, mixed $icon): bool
    {
        if ($icon === null) {
            return false;
        }

        $contents = is_string($icon) && is_file($icon)
            ? file_get_contents($icon)
            : (method_exists($icon, 'getContent') ? $icon->getContent() : null);
        if (! is_string($contents) || $contents === '') {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.server_icon_unreadable'));
        }
        if (strlen($contents) > (int) config('minecrafttoolkit.max_icon_bytes', 2097152)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.server_icon_too_large'));
        }

        $pngSignature = "\x89PNG\r\n\x1a\n";
        $dimensions = strlen($contents) >= 24
            ? unpack('Nwidth/Nheight', substr($contents, 16, 8))
            : false;
        if (! str_starts_with($contents, $pngSignature)
            || ! is_array($dimensions)
            || $dimensions['width'] !== 64
            || $dimensions['height'] !== 64) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.server_icon_format'));
        }

        $this->files->writeAtomically($server, '/server-icon.png', $contents);

        return true;
    }

    private function assertStartupPermission(Server $server, ?string $startup): void
    {
        if ($startup === null) {
            return;
        }

        $this->riskGate->assertAllowed('startup_edits', $server);

        $user = user();
        if ($user === null || (! $user->isRootAdmin()
            && $server->owner_id !== $user->id
            && ! $user->can(SubuserPermission::StartupUpdate, $server))) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.modloader_startup_permission')
            );
        }
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
            Log::warning('Minecraft Toolkit could not persist an action log.', [
                'server_uuid' => $server->uuid,
                'action' => $action,
                'exception' => $exception,
            ]);
        }
    }
}
