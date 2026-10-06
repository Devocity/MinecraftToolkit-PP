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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MinecraftVersionChangeService
{
    public function __construct(
        private readonly MinecraftSoftwareService $software,
        private readonly MinecraftCompatibilityService $compatibility,
        private readonly MinecraftUpdateService $updates,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftRiskGateService $riskGate,
    ) {}

    /**
     * @return array{setup: MinecraftToolkitSetup, updated: int, failed: int, removed: int, errors: string[]}
     */
    public function change(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion,
        string $mode
    ): array {
        if (! in_array($mode, ['safe', 'remove', 'risk'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.change_strategy_invalid'));
        }
        if ($minecraftVersion === $setup->minecraft_version
            && ($loaderVersion ?? null) === ($setup->loader_version ?? null)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.choose_different_version'));
        }
        if ($mode === 'risk') {
            $this->riskGate->assertAllowed('version_risk', $server);
        }
        if ($mode === 'remove') {
            $this->riskGate->assertAllowed('package_removal', $server);
        }

        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.version-change.{$server->uuid}", 1200);
        if (! $lock->get()) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.version_change_running'));
        }

        try {
            $oldMinecraftVersion = $setup->minecraft_version;
            $this->state->assertOffline($server);
            $download = $this->resolveTarget($setup, $minecraftVersion, $loaderVersion);
            $this->assertStartupPermission($server, $download['startup']);
            $report = $this->compatibility->check($server, $setup, $minecraftVersion, $loaderVersion);
            if ($mode === 'safe' && $report['blocking'] > 0) {
                throw new MinecraftToolkitException(
                    trans('minecrafttoolkit::strings.messages.safe_change_blocked')
                );
            }

            $oldStartup = $server->startup;
            $serverPackage = MinecraftToolkitPackage::query()
                ->where('server_uuid', $server->uuid)
                ->whereIn('package_type', ['server_jar', 'server_binary'])
                ->first();
            if (! $serverPackage instanceof MinecraftToolkitPackage) {
                throw new MinecraftToolkitException(
                    trans('minecrafttoolkit::strings.messages.managed_server_file_not_found')
                );
            }
            $oldArtifact = $serverPackage->file_path;
            $newArtifact = '/'.$download['file_name'];
            if ($newArtifact !== $oldArtifact && $this->files->exists($server, $newArtifact)) {
                throw new MinecraftToolkitException(
                    trans('minecrafttoolkit::strings.messages.new_serverfile_exists', ['file' => $download['file_name']])
                );
            }

            $backup = $this->files->backupIfPresent($server, $oldArtifact);
            $runBackup = null;
            if ($download['installer'] && $this->files->exists($server, '/run.sh')) {
                $runBackup = $this->files->backupIfPresent($server, '/run.sh');
            }

            try {
                $this->downloadTarget($server, $download);
                if (is_string($download['startup'])) {
                    $server->forceFill(['startup' => $download['startup']])->saveOrFail();
                }
            } catch (\Throwable $exception) {
                $this->restoreServerArtifact(
                    $server,
                    $oldStartup,
                    $oldArtifact,
                    $newArtifact,
                    $backup,
                    $runBackup
                );
                throw $exception;
            }

            $targetSetup = $this->compatibility->targetSetup($setup, $minecraftVersion, $loaderVersion);
            try {
                DB::transaction(function () use (
                    $setup,
                    $serverPackage,
                    $minecraftVersion,
                    $targetSetup,
                    $download,
                    $newArtifact
                ): void {
                    $isBedrock = $setup->software === 'bedrock';
                    $setup->forceFill([
                        'minecraft_version' => $minecraftVersion,
                        'loader_version' => $targetSetup->loader_version,
                        'server_jar_path' => (! $download['installer'] && ! $isBedrock) ? $newArtifact : null,
                        'server_binary_path' => $isBedrock ? '/bedrock_server' : ($download['installer'] ? '/run.sh' : null),
                        'last_error' => null,
                    ])->saveOrFail();

                    $serverPackage->forceFill([
                        'source' => $download['source'],
                        'source_version_id' => $download['version_id'],
                        'loader' => $targetSetup->loader,
                        'minecraft_version' => $minecraftVersion,
                        'version_number' => $download['version_id'],
                        'file_name' => $download['file_name'],
                        'file_path' => $newArtifact,
                        'download_url' => $download['url'],
                        'dependencies_json' => is_string($download['sha256'])
                            ? ['sha256' => $download['sha256']]
                            : null,
                        'installed_at' => now(),
                    ])->saveOrFail();
                });
            } catch (\Throwable $exception) {
                $this->restoreServerArtifact(
                    $server,
                    $oldStartup,
                    $oldArtifact,
                    $newArtifact,
                    $backup,
                    $runBackup
                );
                throw $exception;
            }

            $removal = $mode === 'remove'
                ? $this->removeBlockingPackages($server, $report['packages'])
                : ['removed' => 0, 'errors' => []];
            $removed = $removal['removed'];
            $updated = 0;
            $failed = count($removal['errors']);
            $errors = $removal['errors'];
            foreach ($report['packages'] as $result) {
                if (! in_array($result['status'], ['update_required', 'system_update'], true)) {
                    continue;
                }

                try {
                    $this->updates->updatePackage($server, $targetSetup, (int) $result['id']);
                    $updated++;
                } catch (MinecraftToolkitException $exception) {
                    $failed++;
                    $errors[] = "{$result['name']}: {$exception->getMessage()}";
                }
            }

            $this->log($server, 'minecraft_version_changed', $failed > 0 ? 'warning' : 'success', trans('minecrafttoolkit::strings.messages.version_changed', [
                'from' => $oldMinecraftVersion,
                'to' => $minecraftVersion,
                'updated' => $updated,
                'removed' => $removed,
                'failed' => $failed,
            ]), [
                'mode' => $mode,
                'loader_version' => $loaderVersion,
                'backup' => $backup,
                'errors' => $errors,
            ]);

            return [
                'setup' => $setup->refresh(),
                'updated' => $updated,
                'failed' => $failed,
                'removed' => $removed,
                'errors' => $errors,
            ];
        } catch (MinecraftToolkitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.version_change_failed'),
                previous: $exception
            );
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function resolveTarget(
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): array {
        if (! array_key_exists($minecraftVersion, $this->software->versionOptions($setup->software))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.version_unavailable_for_software'));
        }

        return $this->software->resolveInstallation($setup->software, $minecraftVersion, $loaderVersion);
    }

    /** @param array<string, mixed> $download */
    private function downloadTarget(Server $server, array $download): void
    {
        if (strtolower(pathinfo((string) $download['file_name'], PATHINFO_EXTENSION)) === 'zip') {
            $this->files->pullFile($server, $download['url'], $download['file_name'], ['zip']);

            return;
        }
        if (is_string($download['sha256'])) {
            $this->files->downloadJar(
                $server,
                $download['url'],
                '/'.$download['file_name'],
                ['sha256' => $download['sha256']]
            );

            return;
        }

        $this->files->pullJar($server, $download['url'], $download['file_name']);
    }

    /** @param array<int, array<string, mixed>> $results
     * @return array{removed: int, errors: string[]}
     */
    private function removeBlockingPackages(Server $server, array $results): array
    {
        $removed = 0;
        $errors = [];
        foreach ($results as $result) {
            if (! in_array($result['status'], ['incompatible', 'unknown', 'pinned'], true)) {
                continue;
            }

            $package = MinecraftToolkitPackage::query()
                ->whereKey($result['id'])
                ->where('server_uuid', $server->uuid)
                ->where('enabled', true)
                ->first();
            if (! $package instanceof MinecraftToolkitPackage) {
                continue;
            }

            try {
                $this->files->backupIfPresent($server, $package->file_path);
                $package->forceFill(['enabled' => false, 'managed' => false])->saveOrFail();
                $removed++;
            } catch (\Throwable $exception) {
                report($exception);
                $errors[] = trans('minecrafttoolkit::strings.messages.package_backup_disable_failed', ['package' => $package->project_name]);
            }
        }

        return compact('removed', 'errors');
    }

    private function restoreServerArtifact(
        Server $server,
        string $oldStartup,
        string $oldArtifact,
        string $newArtifact,
        ?string $backup,
        ?string $runBackup
    ): void {
        try {
            $server->forceFill(['startup' => $oldStartup])->save();
            if ($this->files->exists($server, $newArtifact)) {
                $this->files->delete($server, $newArtifact);
            }
            if ($backup !== null) {
                $this->files->move($server, $backup, $oldArtifact);
            }
            if ($runBackup !== null && ! $this->files->exists($server, '/run.sh')) {
                $this->files->move($server, $runBackup, '/run.sh');
            }
        } catch (\Throwable $exception) {
            Log::error('Minecraft Toolkit could not restore a failed version change.', [
                'server_uuid' => $server->uuid,
                'exception' => $exception,
            ]);
        }
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
                trans('minecrafttoolkit::strings.messages.version_change_startup_permission')
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
            Log::warning('Minecraft Toolkit could not persist a version-change log.', ['exception' => $exception]);
        }
    }
}
