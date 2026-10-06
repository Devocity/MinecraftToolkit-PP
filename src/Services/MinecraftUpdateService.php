<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitUpdateCheck;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MinecraftUpdateService
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly GeyserDownloadService $geyser,
        private readonly MinecraftSoftwareService $software,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftPackageInstaller $installer,
    ) {}

    /** @return array<int, MinecraftToolkitUpdateCheck> */
    public function checkAll(Server $server, MinecraftToolkitSetup $setup): array
    {
        $checks = [];
        foreach ($this->managedPackages($server) as $package) {
            try {
                if ($package->update_pinned) {
                    $checks[] = $this->storeCheck(
                        $package,
                        'pinned',
                        trans('minecrafttoolkit::strings.messages.update_pinned')
                    );

                    continue;
                }

                $package = $this->syncPackageWithInstalledState($server, $package);
                if (($runtimeIssue = $this->runtimeIssue($server, $package)) !== null) {
                    $checks[] = $this->storeCheck($package, 'error', $runtimeIssue);

                    continue;
                }
                if (($dependencyIssue = $this->missingRequiredDependencyIssue($server, $package)) !== null) {
                    $checks[] = $this->storeCheck($package, 'error', $dependencyIssue);

                    continue;
                }

                $candidate = $this->candidate($package, $setup);
                $sameVersion = $this->sameCandidateVersion($package, $candidate);
                $status = $sameVersion ? 'up_to_date' : 'update_available';
                $message = $status === 'up_to_date'
                    ? trans('minecrafttoolkit::strings.messages.package_current')
                    : trans('minecrafttoolkit::strings.messages.package_update_available', ['version' => $candidate['version_number']]);
                $checks[] = $this->storeCheck($package, $status, $message, $candidate);
            } catch (MinecraftToolkitException $exception) {
                $checks[] = $this->storeCheck($package, 'error', $exception->getMessage());
            } catch (\Throwable $exception) {
                report($exception);
                $checks[] = $this->storeCheck(
                    $package,
                    'error',
                    trans('minecrafttoolkit::strings.messages.update_check_failed')
                );
            }
        }

        $this->log($server, 'updates_checked', 'info', trans('minecrafttoolkit::strings.messages.updates_checked', ['count' => count($checks)]));

        return $checks;
    }

    public function updatePackage(Server $server, MinecraftToolkitSetup $setup, int $packageId, bool $forceReinstall = false): MinecraftToolkitPackage
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (! $package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.managed_package_not_found'));
        }
        if ($package->update_pinned) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_pinned_unpin_first'));
        }
        if ($this->isServerArtifact($package)) {
            return $this->updateServerPackage($server, $setup, $package, $forceReinstall);
        }

        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.update.{$server->uuid}", 600);
        if (! $lock->get()) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_update_running'));
        }

        $candidate = null;
        try {
            $this->state->assertOffline($server);
            $package = $this->syncPackageWithInstalledState($server, $package);
            $candidate = $this->candidateFromLatestUpdateCheck($package) ?? $this->candidate($package, $setup);
            if (! $forceReinstall && $this->sameCandidateVersion($package, $candidate)) {
                $this->storeCheck($package, 'up_to_date', trans('minecrafttoolkit::strings.messages.package_already_current'), $candidate);

                return $package;
            }

            $oldPath = $package->file_path;
            $newPath = dirname($oldPath).'/'.$candidate['file_name'];
            if ($newPath !== $oldPath && $this->files->exists($server, $newPath)) {
                throw new MinecraftToolkitException(
                    trans('minecrafttoolkit::strings.messages.new_package_file_exists', ['file' => $candidate['file_name']])
                );
            }
            $backup = $this->files->backupIfPresent($server, $oldPath);

            try {
                $metadata = $this->files->downloadJarWithMetadata(
                    $server,
                    $candidate['url'],
                    $newPath,
                    $candidate['hashes'],
                    function (array $downloadedMetadata) use ($package, $candidate): void {
                        $this->assertDownloadedPackageMatchesCandidate($package, $candidate, $downloadedMetadata);
                    }
                );
                if (! $this->files->exists($server, $newPath)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.update_not_confirmed'));
                }
                if ($newPath !== $oldPath && $this->files->exists($server, $oldPath)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.old_package_file_remains'));
                }
            } catch (\Throwable $exception) {
                if ($backup !== null) {
                    try {
                        if ($this->files->exists($server, $newPath)) {
                            $this->files->delete($server, $newPath);
                        }
                        $this->files->move($server, $backup, $oldPath);
                    } catch (\Throwable $restoreException) {
                        Log::error('Minecraft Toolkit could not restore a package backup.', [
                            'server_uuid' => $server->uuid,
                            'package_id' => $package->id,
                            'exception' => $restoreException,
                        ]);
                    }
                }

                throw $exception;
            }

            $oldVersionId = $package->source_version_id;
            $oldVersionNumber = $package->version_number;
            $package->forceFill([
                'source_version_id' => $candidate['version_id'],
                'version_number' => $candidate['version_number'],
                'file_name' => $candidate['file_name'],
                'file_path' => $newPath,
                'download_url' => $candidate['url'],
                'sha1' => $metadata['sha1'] ?? ($candidate['hashes']['sha1'] ?? null),
                'sha512' => $metadata['sha512'] ?? ($candidate['hashes']['sha512'] ?? null),
                'dependencies_json' => $candidate['dependencies'],
                'minecraft_version' => $setup->minecraft_version,
                'loader' => $setup->loader ?? $setup->software,
                'installed_at' => now(),
                'last_checked_at' => now(),
            ])->save();

            MinecraftToolkitUpdateCheck::query()->create([
                'server_uuid' => $package->server_uuid,
                'package_id' => $package->id,
                'old_version_id' => $oldVersionId,
                'new_version_id' => $candidate['version_id'],
                'old_version_number' => $oldVersionNumber,
                'new_version_number' => $candidate['version_number'],
                'status' => 'up_to_date',
                'message' => trans('minecrafttoolkit::strings.messages.package_updated'),
                'candidate_json' => Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json') ? $candidate : null,
                'checked_at' => now(),
            ]);
            $this->log(
                $server,
                'package_updated',
                'success',
                trans('minecrafttoolkit::strings.messages.package_updated_to', ['package' => $package->project_name, 'version' => $candidate['version_number']]),
                ['package_id' => $package->id, 'backup' => $backup]
            );

            return $package->refresh();
        } catch (MinecraftToolkitException $exception) {
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $exception->getMessage());
            }
            $this->log($server, 'package_update_failed', 'error', $exception->getMessage(), [
                'package_id' => $package->id,
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $message = trans('minecrafttoolkit::strings.messages.package_update_failed');
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $message);
            }
            $this->log($server, 'package_update_failed', 'error', $message, ['package_id' => $package->id]);
            throw new MinecraftToolkitException($message, previous: $exception);
        } finally {
            $lock->release();
        }
    }

    /** @return array{updated: int, failed: int, skipped_pinned: int, errors: string[]} */
    public function updateAll(Server $server, MinecraftToolkitSetup $setup): array
    {
        $this->state->assertOffline($server);
        $this->checkAll($server, $setup);
        $updated = 0;
        $failed = 0;
        $skippedPinned = 0;
        $errors = [];

        foreach ($this->managedPackages($server) as $packageRecord) {
            if ($packageRecord->update_pinned) {
                $skippedPinned++;

                continue;
            }

            $check = MinecraftToolkitUpdateCheck::query()
                ->where('server_uuid', $server->uuid)
                ->where('package_id', $packageRecord->id)
                ->latest('id')
                ->first();
            if (! $check instanceof MinecraftToolkitUpdateCheck || $check->status !== 'update_available') {
                continue;
            }

            try {
                $before = $packageRecord->source_version_id;
                $package = $this->updatePackage($server, $setup, (int) $check->package_id);
                if ($package->source_version_id !== $before) {
                    $updated++;
                }
            } catch (MinecraftToolkitException $exception) {
                $failed++;
                $errors[] = $exception->getMessage();
            }
        }

        return [
            'updated' => $updated,
            'failed' => $failed,
            'skipped_pinned' => $skippedPinned,
            'errors' => $errors,
        ];
    }

    public function setPackagePinned(Server $server, int $packageId, bool $pinned): MinecraftToolkitPackage
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (! $package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.managed_package_not_found'));
        }

        $package->forceFill([
            'update_pinned' => $pinned,
            'last_checked_at' => now(),
        ])->save();

        $this->storeCheck(
            $package,
            $pinned ? 'pinned' : 'unchecked',
            $pinned
                ? trans('minecrafttoolkit::strings.messages.package_pinned_check')
                : trans('minecrafttoolkit::strings.messages.package_unpinned_check')
        );
        $this->log($server, $pinned ? 'package_pinned' : 'package_unpinned', 'info', trans(
            $pinned ? 'minecrafttoolkit::strings.messages.package_pinned_log' : 'minecrafttoolkit::strings.messages.package_unpinned_log',
            ['package' => $package->project_name]
        ), ['package_id' => $package->id]);

        return $package->refresh();
    }

    /** @return array{status: string, message: string, metadata: array<string, mixed>} */
    public function verifyPackage(Server $server, int $packageId): array
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (! $package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.managed_package_not_found'));
        }

        if ($package->file_path === '' || ! $this->files->exists($server, $package->file_path)) {
            $message = trans('minecrafttoolkit::strings.messages.managed_file_not_found');
            $this->storeCheck($package, 'error', $message);
            $this->log($server, 'package_verify_failed', 'error', $message, ['package_id' => $package->id]);

            return ['status' => 'error', 'message' => $message, 'metadata' => []];
        }

        try {
            $contents = $this->files->read(
                $server,
                $package->file_path,
                max(1, (int) config('minecrafttoolkit.max_package_bytes', 104857600)) + 1
            );
            $isJarPackage = $this->isJarPackage($package);
            $metadata = $isJarPackage
                ? $this->files->inspectJarContents($contents)
                : $this->files->inspectFileContents($contents);
            $this->assertStoredPackageHashesMatch($package, $metadata);

            if ($isJarPackage && $package->source !== 'geysermc') {
                $this->assertDownloadedPackageMatchesCandidate($package, [
                    'version_number' => (string) ($package->version_number ?? ''),
                ], $metadata);
            }

            $package->forceFill([
                'sha1' => $metadata['sha1'],
                'sha512' => $metadata['sha512'],
            ])->save();

            $message = $isJarPackage
                ? trans('minecrafttoolkit::strings.messages.file_verified_jar', [
                    'sha' => substr((string) $metadata['sha512'], 0, 16).'...',
                    'size' => (int) $metadata['size'],
                ])
                : trans('minecrafttoolkit::strings.messages.file_verified', ['size' => (int) $metadata['size']]);
            $this->storeCheck($package, 'verified', $message);
            $this->log($server, 'package_verified', 'success', $message, [
                'package_id' => $package->id,
                'sha1' => $metadata['sha1'],
                'sha512' => $metadata['sha512'],
                'size' => $metadata['size'],
                'plugin_version' => $metadata['plugin_version'] ?? null,
                'class_major_version' => $metadata['class_major_version'] ?? null,
            ]);

            return ['status' => 'verified', 'message' => $message, 'metadata' => $metadata];
        } catch (MinecraftToolkitException $exception) {
            $this->storeCheck($package, 'error', $exception->getMessage());
            $this->log($server, 'package_verify_failed', 'error', $exception->getMessage(), [
                'package_id' => $package->id,
            ]);

            return ['status' => 'error', 'message' => $exception->getMessage(), 'metadata' => []];
        } catch (\Throwable $exception) {
            report($exception);
            $message = trans('minecrafttoolkit::strings.messages.package_verification_failed');
            $this->storeCheck($package, 'error', $message);
            $this->log($server, 'package_verify_failed', 'error', $message, ['package_id' => $package->id]);

            return ['status' => 'error', 'message' => $message, 'metadata' => []];
        }
    }

    /** @return array{installed: int, skipped: int, errors: string[]} */
    public function installMissingDependencies(Server $server, MinecraftToolkitSetup $setup, int $packageId): array
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (! $package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.managed_package_not_found'));
        }

        $this->state->assertOffline($server);
        $dependencies = $this->missingDependencies($server, $setup, $package);
        if ($dependencies === []) {
            $this->storeCheck($package, 'up_to_date', trans('minecrafttoolkit::strings.messages.dependencies_already_installed'));

            return ['installed' => 0, 'skipped' => 0, 'errors' => []];
        }

        $installed = 0;
        $skipped = 0;
        $errors = [];
        foreach ($dependencies as $dependency) {
            $dependencyProjectId = is_string($dependency['project_id'] ?? null)
                ? trim((string) $dependency['project_id'])
                : '';
            if ($dependencyProjectId === '') {
                $dependencyProjectId = $this->dependencyProjectIdFromTitle((string) ($dependency['title'] ?? ''));
            }
            if ($dependencyProjectId === '') {
                $skipped++;
                $errors[] = trans('minecrafttoolkit::strings.messages.dependency_unresolved');

                continue;
            }

            try {
                if ($package->source === 'modrinth') {
                    $this->installer->installModrinthPackage($server, $setup, $dependencyProjectId);
                } elseif ($package->source === 'curseforge') {
                    $this->installer->installCurseForgePackage($server, $setup, $dependencyProjectId);
                } else {
                    $skipped++;
                    $errors[] = trans('minecrafttoolkit::strings.messages.dependencies_source_unsupported', ['source' => $package->source]);

                    continue;
                }
                $installed++;
            } catch (MinecraftToolkitException $exception) {
                if (str_contains($exception->getMessage(), 'bereits von Minecraft Toolkit verwaltet')
                    || str_contains($exception->getMessage(), 'already managed by Minecraft Toolkit')) {
                    $skipped++;

                    continue;
                }
                $errors[] = $exception->getMessage();
            }
        }

        $status = $errors === [] ? 'up_to_date' : 'error';
        $message = $errors === []
            ? trans('minecrafttoolkit::strings.messages.dependencies_installed', ['count' => $installed])
            : trans('minecrafttoolkit::strings.messages.dependencies_installed_with_errors', ['count' => $installed, 'errors' => count($errors)]);
        $this->storeCheck($package, $status, $message);
        $this->log($server, 'dependencies_installed', $errors === [] ? 'success' : 'warning', $message, [
            'package_id' => $package->id,
            'installed' => $installed,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('installed', 'skipped', 'errors');
    }

    public function deletePackage(Server $server, int $packageId): void
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->first();
        if (! $package instanceof MinecraftToolkitPackage) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.managed_package_not_found'));
        }

        $this->state->assertOffline($server);
        $package = $this->syncPackageWithInstalledState($server, $package);
        $backup = null;
        if ($package->file_path !== '' && $this->files->exists($server, $package->file_path)) {
            $backup = $this->files->backupIfPresent($server, $package->file_path);
        }

        $package->forceFill([
            'enabled' => false,
            'managed' => false,
            'last_checked_at' => now(),
        ])->save();

        $this->log($server, 'package_deleted', 'warning', trans('minecrafttoolkit::strings.messages.package_deleted', ['package' => $package->project_name]), [
            'package_id' => $package->id,
            'backup' => $backup,
        ]);
    }

    /** @return array<string, mixed> */
    public function candidate(MinecraftToolkitPackage $package, MinecraftToolkitSetup $setup): array
    {
        if ($this->isServerArtifact($package)) {
            return $this->serverCandidate($package, $setup);
        }

        if ($package->source === 'modrinth') {
            $candidate = $this->modrinth->updateCandidate($package->source_project_id, $setup);

            return $this->normalizeModrinthCandidate($candidate);
        }

        if ($package->source === 'curseforge') {
            $candidate = $this->curseForge->updateCandidate($package->source_project_id, $setup);

            return $this->normalizeModrinthCandidate($candidate);
        }

        if ($package->source === 'geysermc'
            && in_array($package->source_project_id, ['geyser', 'floodgate'], true)) {
            $download = $this->geyser->latestSpigot($package->source_project_id);

            return $this->normalizeGeyserCandidate($download);
        }

        throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.no_auto_update_check'));
    }

    public function reinstallPackage(Server $server, MinecraftToolkitSetup $setup, int $packageId): MinecraftToolkitPackage
    {
        $package = $this->updatePackage($server, $setup, $packageId, true);
        $this->log($server, 'package_reinstalled', 'success', trans('minecrafttoolkit::strings.messages.package_reinstalled', ['package' => $package->project_name]), [
            'package_id' => $package->id,
        ]);

        return $package;
    }

    public function setPackageEnabled(Server $server, int $packageId, bool $enabled): MinecraftToolkitPackage
    {
        $package = MinecraftToolkitPackage::query()
            ->whereKey($packageId)->where('server_uuid', $server->uuid)->where('managed', true)->first();
        if (! $package instanceof MinecraftToolkitPackage || $this->isServerArtifact($package)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.package_cannot_disable'));
        }

        $this->state->assertOffline($server);
        $path = (string) $package->file_path;
        if ($enabled) {
            $target = preg_replace('/\.disabled$/', '', $path) ?? $path;
            if ($path !== $target && $this->files->exists($server, $path)) {
                if ($this->files->exists($server, $target)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.active_target_exists'));
                }
                $this->files->move($server, $path, $target);
            }
            $path = $target;
        } elseif (! str_ends_with($path, '.disabled')) {
            $target = $path.'.disabled';
            if ($this->files->exists($server, $target)) {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.disabled_target_exists'));
            }
            if ($this->files->exists($server, $path)) {
                $this->files->move($server, $path, $target);
            }
            $path = $target;
        }

        $package->forceFill(['enabled' => $enabled, 'file_path' => $path, 'file_name' => basename($path)])->save();
        $this->log($server, $enabled ? 'package_enabled' : 'package_disabled', 'info',
            trans($enabled ? 'minecrafttoolkit::strings.messages.package_enabled' : 'minecrafttoolkit::strings.messages.package_disabled', ['package' => $package->project_name]), ['package_id' => $package->id]);

        return $package->refresh();
    }

    private function updateServerPackage(
        Server $server,
        MinecraftToolkitSetup $setup,
        MinecraftToolkitPackage $package,
        bool $forceReinstall = false
    ): MinecraftToolkitPackage {
        /** @var Lock $lock */
        $lock = Cache::lock("minecrafttoolkit.server-update.{$server->uuid}", 600);
        if (! $lock->get()) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.server_update_running'));
        }

        $candidate = null;
        try {
            $this->state->assertOffline($server);
            $candidate = $this->candidateFromLatestUpdateCheck($package) ?? $this->serverCandidate($package, $setup);
            if (! $forceReinstall && $this->sameCandidateVersion($package, $candidate)) {
                $this->storeCheck($package, 'up_to_date', trans('minecrafttoolkit::strings.messages.server_file_already_current'), $candidate);

                return $package;
            }

            $oldPath = $package->file_path;
            $newPath = $this->serverTargetPath($package, $candidate);
            if ($newPath !== $oldPath && $this->files->exists($server, $newPath)) {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.new_server_file_exists', ['file' => $candidate['file_name']]));
            }

            $backup = $this->files->backupIfPresent($server, $oldPath);
            $metadata = [];
            try {
                if (strtolower(pathinfo((string) $candidate['file_name'], PATHINFO_EXTENSION)) === 'zip') {
                    $metadata = $this->files->downloadFile($server, (string) $candidate['url'], (string) $candidate['file_name'], ['zip']);
                } else {
                    $metadata = $this->files->downloadJarWithMetadata(
                        $server,
                        (string) $candidate['url'],
                        $newPath,
                        is_array($candidate['hashes'] ?? null) ? $candidate['hashes'] : []
                    );
                }

                if (! $this->files->exists($server, $newPath)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.server_update_not_confirmed'));
                }
                if ($newPath !== $oldPath && $this->files->exists($server, $oldPath)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.old_server_file_remains'));
                }
            } catch (\Throwable $exception) {
                if ($backup !== null) {
                    try {
                        if ($this->files->exists($server, $newPath)) {
                            $this->files->delete($server, $newPath);
                        }
                        $this->files->move($server, $backup, $oldPath);
                    } catch (\Throwable $restoreException) {
                        Log::error('Minecraft Toolkit could not restore a server artifact backup.', [
                            'server_uuid' => $server->uuid,
                            'package_id' => $package->id,
                            'exception' => $restoreException,
                        ]);
                    }
                }

                throw $exception;
            }

            $oldVersionId = $package->source_version_id;
            $oldVersionNumber = $package->version_number;
            $package->forceFill([
                'source' => (string) ($candidate['source'] ?? $package->source),
                'source_project_id' => (string) ($candidate['project_id'] ?? $package->source_project_id),
                'source_version_id' => (string) $candidate['version_id'],
                'version_number' => (string) $candidate['version_number'],
                'file_name' => (string) $candidate['file_name'],
                'file_path' => $newPath,
                'download_url' => (string) $candidate['url'],
                'sha1' => $metadata['sha1'] ?? ($candidate['hashes']['sha1'] ?? null),
                'sha512' => $metadata['sha512'] ?? ($candidate['hashes']['sha512'] ?? null),
                'dependencies_json' => is_array($candidate['dependencies'] ?? null) ? $candidate['dependencies'] : [],
                'minecraft_version' => (string) ($candidate['minecraft_version'] ?? $setup->minecraft_version),
                'loader' => (string) ($candidate['loader'] ?? $setup->loader ?? $setup->software),
                'installed_at' => now(),
                'last_checked_at' => now(),
            ])->save();

            $setupUpdates = [
                'minecraft_version' => (string) ($candidate['minecraft_version'] ?? $setup->minecraft_version),
                'last_error' => null,
            ];
            if ((bool) ($candidate['installer'] ?? false)) {
                $setupUpdates['server_jar_path'] = null;
                $setupUpdates['server_binary_path'] = (string) ($candidate['runtime_path'] ?? '/run.sh');
            } elseif ($package->package_type === 'server_jar') {
                $setupUpdates['server_jar_path'] = $newPath;
                $setupUpdates['server_binary_path'] = null;
            } else {
                $setupUpdates['server_jar_path'] = null;
                $setupUpdates['server_binary_path'] = (string) ($candidate['runtime_path'] ?? $newPath);
            }
            if (is_string($candidate['startup'] ?? null) && $candidate['startup'] !== '') {
                $server->forceFill(['startup' => $candidate['startup']])->saveOrFail();
            }
            $setup->forceFill($setupUpdates)->save();

            MinecraftToolkitUpdateCheck::query()->create([
                'server_uuid' => $package->server_uuid,
                'package_id' => $package->id,
                'old_version_id' => $oldVersionId,
                'new_version_id' => (string) $candidate['version_id'],
                'old_version_number' => $oldVersionNumber,
                'new_version_number' => (string) $candidate['version_number'],
                'status' => 'up_to_date',
                'message' => trans('minecrafttoolkit::strings.messages.server_file_updated'),
                'candidate_json' => Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json') ? $candidate : null,
                'checked_at' => now(),
            ]);
            $this->log($server, 'server_artifact_updated', 'success', trans('minecrafttoolkit::strings.messages.package_updated_to', ['package' => $package->project_name, 'version' => $candidate['version_number']]), [
                'package_id' => $package->id,
                'backup' => $backup,
            ]);

            return $package->refresh();
        } catch (MinecraftToolkitException $exception) {
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $exception->getMessage());
            }
            $this->log($server, 'server_artifact_update_failed', 'error', $exception->getMessage(), [
                'package_id' => $package->id,
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $message = trans('minecrafttoolkit::strings.messages.server_update_failed');
            if ($candidate !== null) {
                $this->storeCheck($package, 'error', $message);
            }
            $this->log($server, 'server_artifact_update_failed', 'error', $message, ['package_id' => $package->id]);
            throw new MinecraftToolkitException($message, previous: $exception);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function serverCandidate(MinecraftToolkitPackage $package, MinecraftToolkitSetup $setup): array
    {
        $software = (string) ($package->source_project_id ?: $setup->software);
        $targetVersion = $software === 'bedrock' ? 'latest' : (string) $setup->minecraft_version;
        $installation = $this->software->resolveInstallation(
            $software,
            $targetVersion,
            $setup->loader
        );

        $fileName = $this->installer->safeFileName((string) ($installation['file_name'] ?? $package->file_name));
        $versionId = (string) ($installation['version_id'] ?? $targetVersion);
        $displayVersion = $software === 'bedrock'
            ? $versionId
            : trim($targetVersion.(($versionId !== '' && $versionId !== $targetVersion) ? ' / '.$versionId : ''));
        $hashes = [];
        if (is_string($installation['sha256'] ?? null) && $installation['sha256'] !== '') {
            $hashes['sha256'] = $installation['sha256'];
        }

        return [
            'version_id' => $versionId,
            'version_number' => $displayVersion,
            'file_name' => $fileName,
            'url' => (string) ($installation['url'] ?? ''),
            'hashes' => $hashes,
            'dependencies' => [],
            'source' => (string) ($installation['source'] ?? $package->source),
            'project_id' => $software,
            'minecraft_version' => $software === 'bedrock' ? $versionId : $targetVersion,
            'loader' => (string) ($setup->loader ?? $software),
            'startup' => is_string($installation['startup'] ?? null) ? $installation['startup'] : null,
            'installer' => (bool) ($installation['installer'] ?? false),
            'runtime_path' => $package->package_type === 'server_binary'
                || (bool) ($installation['installer'] ?? false)
                ? ($software === 'bedrock' ? '/bedrock_server' : '/run.sh')
                : null,
        ];
    }

    /** @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function normalizeModrinthCandidate(array $candidate): array
    {
        $version = $candidate['version'] ?? null;
        $file = is_array($version) ? ($version['selected_file'] ?? null) : null;
        if (! is_array($version) || ! is_array($file)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_update_info_incomplete'));
        }

        return [
            'version_id' => (string) $version['id'],
            'version_number' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
            'file_name' => $this->installer->safeFileName((string) $file['filename']),
            'url' => (string) $file['url'],
            'hashes' => is_array($file['hashes'] ?? null) ? $file['hashes'] : [],
            'dependencies' => is_array($candidate['dependencies'] ?? null) ? $candidate['dependencies'] : [],
        ];
    }

    /** @param array<string, mixed> $download
     * @return array<string, mixed>
     */
    public function normalizeGeyserCandidate(array $download): array
    {
        return [
            'version_id' => (string) $download['build'],
            'version_number' => $download['version'].'+'.$download['build'],
            'file_name' => $this->installer->safeFileName((string) $download['file_name']),
            'url' => (string) $download['url'],
            'hashes' => ['sha256' => (string) $download['sha256']],
            'dependencies' => ['sha256' => (string) $download['sha256']],
        ];
    }

    private function syncPackageWithInstalledState(Server $server, MinecraftToolkitPackage $package): MinecraftToolkitPackage
    {
        try {
            $log = $this->readLatestLog($server);
            $logIsFresh = ! $this->latestLogIsOlderThanPackageInstall($log, $package);
            $loaded = $logIsFresh ? $this->loadedPluginVersions($log) : [];
            $actualVersion = $this->matchLoadedVersion($package, $loaded);
            $actualFileName = $this->matchInstalledFileName($server, $package);

            $changes = [];
            if ($actualVersion !== null
                && $package->source !== 'geysermc'
                && ! $this->versionsEquivalent($actualVersion, (string) $package->version_number)
            ) {
                $changes['version_number'] = $actualVersion;
            }
            if ($actualFileName !== null && $actualFileName !== $package->file_name) {
                $changes['file_name'] = $actualFileName;
                $changes['file_path'] = dirname($package->file_path ?: '/plugins/x.jar').'/'.$actualFileName;
            }
            if ($changes !== []) {
                $changes['last_checked_at'] = now();
                $package->forceFill($changes)->save();
                $this->log($server, 'package_synced', 'info', trans('minecrafttoolkit::strings.messages.package_synced', ['package' => $package->project_name]), [
                    'package_id' => $package->id,
                    'changes' => $changes,
                ]);
                $package = $package->refresh();
            }
        } catch (\Throwable $exception) {
            Log::warning('Minecraft Toolkit could not sync package with installed state.', [
                'server_uuid' => $server->uuid,
                'package_id' => $package->id,
                'exception' => $exception::class,
            ]);
        }

        return $package;
    }

    private function runtimeIssue(Server $server, MinecraftToolkitPackage $package): ?string
    {
        try {
            $log = $this->readLatestLog($server);
        } catch (\Throwable) {
            return null;
        }
        if ($this->latestLogIsOlderThanPackageInstall($log, $package)) {
            return null;
        }

        $fileName = preg_quote($package->file_name, '/');
        if ($fileName !== '' && preg_match('/Could not load.*'.$fileName.'.*?Unknown\/missing dependency plugins: \[([^\]]+)\]/s', $log, $matches)) {
            return trans('minecrafttoolkit::strings.messages.runtime_missing_dependency', ['dependency' => $matches[1]]);
        }
        if ($fileName !== '' && preg_match('/Could not load.*'.$fileName.'.*?compiled by a more recent version of the Java Runtime.*?class file version ([0-9.]+).*?recognizes class file versions up to ([0-9.]+)/s', $log, $matches)) {
            return trans('minecrafttoolkit::strings.messages.runtime_java_too_old', ['class' => $matches[1], 'max' => $matches[2]]);
        }

        $name = preg_quote($this->normalName($package->project_name), '/');
        if ($name !== '' && preg_match('/Could not load[^\n]*(?:'.$name.')[\s\S]{0,1200}?Unknown\/missing dependency plugins: \[([^\]]+)\]/i', $log, $matches)) {
            return trans('minecrafttoolkit::strings.messages.runtime_missing_dependency', ['dependency' => $matches[1]]);
        }
        if ($name !== '' && preg_match('/Could not load[^\n]*(?:'.$name.')[\s\S]{0,1600}?compiled by a more recent version of the Java Runtime[\s\S]{0,400}?class file version ([0-9.]+)[\s\S]{0,400}?recognizes class file versions up to ([0-9.]+)/i', $log, $matches)) {
            return trans('minecrafttoolkit::strings.messages.runtime_java_too_old', ['class' => $matches[1], 'max' => $matches[2]]);
        }

        return null;
    }

    private function missingRequiredDependencyIssue(Server $server, MinecraftToolkitPackage $package): ?string
    {
        $dependencies = $package->dependencies_json;
        if (! is_array($dependencies)) {
            $dependencies = [];
        }
        $dependencies = array_merge($dependencies, $this->knownRequiredDependenciesForPackage($package));
        $runtimeDependency = $this->runtimeMissingDependency($server, $package);
        if ($runtimeDependency !== null) {
            $dependencies[] = [
                'project_id' => $this->dependencyProjectIdFromTitle($runtimeDependency),
                'type' => 'required',
                'title' => $runtimeDependency,
                'slug' => $this->dependencyProjectIdFromTitle($runtimeDependency),
            ];
        }

        $installed = $this->installedDependencyIdentifiers($server);
        $missing = collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && ! $this->dependencyIsInstalled($dependency, $installed))
            ->pluck('title')
            ->filter()
            ->values()
            ->all();

        if ($missing === []) {
            return null;
        }

        return trans('minecrafttoolkit::strings.messages.missing_dependencies', ['list' => implode(', ', $missing)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function missingDependencies(Server $server, MinecraftToolkitSetup $setup, MinecraftToolkitPackage $package): array
    {
        $dependencies = is_array($package->dependencies_json) ? $package->dependencies_json : [];
        try {
            $candidate = $this->candidate($package, $setup);
            if (is_array($candidate['dependencies'] ?? null)) {
                $dependencies = array_merge($dependencies, $candidate['dependencies']);
            }
        } catch (\Throwable) {
            // Existing metadata and known rules are still useful when the source API is unavailable.
        }
        $dependencies = array_merge($dependencies, $this->knownRequiredDependenciesForPackage($package));
        $runtimeDependency = $this->runtimeMissingDependency($server, $package);
        if ($runtimeDependency !== null) {
            $dependencies[] = [
                'project_id' => $this->dependencyProjectIdFromTitle($runtimeDependency),
                'type' => 'required',
                'title' => $runtimeDependency,
                'slug' => $this->dependencyProjectIdFromTitle($runtimeDependency),
            ];
        }

        $installed = $this->installedDependencyIdentifiers($server);

        return collect($dependencies)
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && ($dependency['type'] ?? null) === 'required'
                && ! $this->dependencyIsInstalled($dependency, $installed))
            ->unique(fn (array $dependency): string => strtolower((string) ($dependency['project_id'] ?? $dependency['slug'] ?? $dependency['title'] ?? '')))
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function knownRequiredDependenciesForPackage(MinecraftToolkitPackage $package): array
    {
        $slug = strtolower((string) ($package->source_project_slug ?? ''));
        $name = strtolower($package->project_name);
        if ($slug === 'viarewind' || str_contains($name, 'viarewind')) {
            return [[
                'project_id' => $package->source === 'curseforge' ? null : 'viabackwards',
                'type' => 'required',
                'title' => 'ViaBackwards',
                'slug' => 'viabackwards',
            ]];
        }

        return [];
    }

    /** @return array<int, string> */
    private function installedDependencyIdentifiers(Server $server): array
    {
        return MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->get()
            ->flatMap(fn (MinecraftToolkitPackage $package): array => [
                strtolower((string) $package->source_project_id),
                strtolower((string) $package->source_project_slug),
                $this->normalName($package->project_name),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $dependency
     * @param  array<int, string>  $installed
     */
    private function dependencyIsInstalled(array $dependency, array $installed): bool
    {
        $identifiers = array_filter([
            strtolower((string) ($dependency['project_id'] ?? '')),
            strtolower((string) ($dependency['slug'] ?? '')),
            $this->normalName((string) ($dependency['title'] ?? '')),
        ]);

        foreach ($identifiers as $identifier) {
            if (in_array($identifier, $installed, true)) {
                return true;
            }
        }

        return false;
    }

    private function runtimeMissingDependency(Server $server, MinecraftToolkitPackage $package): ?string
    {
        try {
            $log = $this->readLatestLog($server);
        } catch (\Throwable) {
            return null;
        }
        if ($this->latestLogIsOlderThanPackageInstall($log, $package)) {
            return null;
        }

        $fileName = preg_quote($package->file_name, '/');
        if ($fileName !== '' && preg_match('/Could not load.*'.$fileName.'.*?Unknown\/missing dependency plugins: \[([^\]]+)\]/s', $log, $matches)) {
            return trim(explode(',', $matches[1])[0]);
        }

        $name = preg_quote($this->normalName($package->project_name), '/');
        if ($name !== '' && preg_match('/Could not load[^
]*(?:'.$name.')[\s\S]{0,1200}?Unknown\/missing dependency plugins: \[([^\]]+)\]/i', $log, $matches)) {
            return trim(explode(',', $matches[1])[0]);
        }

        return null;
    }

    private function dependencyProjectIdFromTitle(string $title): string
    {
        $normalized = $this->normalName($title);

        return match ($normalized) {
            'viabackwards' => 'viabackwards',
            default => $normalized,
        };
    }

    /** @return array<string, string> */
    private function loadedPluginVersions(string $log): array
    {
        $versions = [];
        if (preg_match_all('/Loading server plugin ([A-Za-z0-9_.+ -]+) v([^\r\n]+)/', $log, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[$this->normalName($match[1])] = trim($match[2]);
            }
        }
        if (preg_match_all('/ - ([A-Za-z0-9_.+ -]+) \(([^)]+)\)/', $log, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $versions[$this->normalName($match[1])] = trim($match[2]);
            }
        }

        return $versions;
    }

    /** @param array<string, string> $loaded */
    private function matchLoadedVersion(MinecraftToolkitPackage $package, array $loaded): ?string
    {
        $candidates = array_unique(array_filter([
            $this->normalName($package->project_name),
            $this->normalName($package->source_project_slug ?? ''),
            $this->normalName(pathinfo($package->file_name ?? '', PATHINFO_FILENAME)),
            $this->normalName(str_replace(['-bukkit', '-spigot', '-paper'], '', pathinfo($package->file_name ?? '', PATHINFO_FILENAME))),
        ]));

        $aliases = [
            'essentialsx' => ['essentials'],
            'geyser' => ['geyser-spigot', 'geysermc'],
            'floodgate' => ['floodgate-spigot'],
            'simplevoicechat' => ['voicechat', 'voicechat-bukkit'],
            'multiversecore' => ['multiverse-core'],
        ];
        foreach ($candidates as $candidate) {
            foreach ($aliases[$candidate] ?? [] as $alias) {
                $candidates[] = $this->normalName($alias);
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (isset($loaded[$candidate])) {
                return $this->cleanLoadedVersion($loaded[$candidate]);
            }
        }

        return null;
    }

    private function matchInstalledFileName(Server $server, MinecraftToolkitPackage $package): ?string
    {
        $directory = dirname($package->file_path ?: '');
        if (! in_array($directory, ['/plugins', '/mods'], true)) {
            return null;
        }

        $target = $this->normalName($package->project_name);
        foreach ($this->files->listDirectory($server, $directory) as $file) {
            $name = is_string($file['name'] ?? null) ? $file['name'] : '';
            if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'jar') {
                continue;
            }

            $normalized = $this->normalName(pathinfo($name, PATHINFO_FILENAME));
            if ($normalized !== '' && ($normalized === $target || str_contains($normalized, $target) || str_contains($target, $normalized))) {
                return $name;
            }
        }

        return null;
    }

    private function latestLogIsOlderThanPackageInstall(string $log, MinecraftToolkitPackage $package): bool
    {
        if ($package->installed_at === null || $log === '') {
            return false;
        }
        if (! preg_match_all('/\[([0-2][0-9]:[0-5][0-9]:[0-5][0-9])\]/', $log, $matches) || $matches[1] === []) {
            return false;
        }

        $last = end($matches[1]);
        if (! is_string($last)) {
            return false;
        }

        try {
            $logTime = $package->installed_at->copy()->setTimeFromTimeString($last);
            if ($logTime->greaterThan(now()->addMinute())) {
                $logTime = $logTime->subDay();
            }

            return $logTime->lessThan($package->installed_at->copy()->subSeconds(5));
        } catch (\Throwable) {
            return false;
        }
    }

    private function versionsEquivalent(string $a, string $b): bool
    {
        return MinecraftPackageVersionNormalizer::equivalent($a, $b);
    }

    /** @param array<string, mixed> $candidate */
    private function sameCandidateVersion(MinecraftToolkitPackage $package, array $candidate): bool
    {
        if ($this->isServerArtifact($package)) {
            return (string) $candidate['version_id'] === (string) $package->source_version_id;
        }

        return (string) $candidate['version_id'] === (string) $package->source_version_id
            && $this->versionsEquivalent((string) $candidate['version_number'], (string) $package->version_number);
    }

    /** @param array<string, mixed> $candidate */
    private function serverTargetPath(MinecraftToolkitPackage $package, array $candidate): string
    {
        if ($package->package_type === 'server_binary') {
            return '/'.ltrim((string) $candidate['file_name'], '/');
        }

        $directory = dirname((string) ($package->file_path ?: '/server.jar'));
        if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) {
            $directory = '';
        }

        return rtrim($directory, '/').'/'.(string) $candidate['file_name'];
    }

    private function isServerArtifact(MinecraftToolkitPackage $package): bool
    {
        return in_array($package->package_type, ['server_jar', 'server_binary'], true);
    }

    private function isJarPackage(MinecraftToolkitPackage $package): bool
    {
        return strtolower(pathinfo((string) $package->file_name, PATHINFO_EXTENSION)) === 'jar'
            || strtolower(pathinfo((string) $package->file_path, PATHINFO_EXTENSION)) === 'jar';
    }

    private function readLatestLog(Server $server): string
    {
        try {
            return $this->files->read($server, '/logs/latest.log', 1024 * 1024);
        } catch (\Throwable) {
            return '';
        }
    }

    private function normalName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\.(jar)$/', '', $name) ?? $name;
        $name = preg_replace('/\b(bukkit|spigot|paper|plugin|mod)\b/', '', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/', '', $name) ?? $name;

        $aliases = [
            'essentialsx' => 'essentials', 'essentialsxcore' => 'essentials',
            'geyserspigot' => 'geyser', 'geyserbukkit' => 'geyser',
            'floodgatespigot' => 'floodgate', 'floodgatebukkit' => 'floodgate',
            'luckpermsbukkit' => 'luckperms', 'luckpermspaper' => 'luckperms',
            'viaversionbukkit' => 'viaversion', 'viabackwardsbukkit' => 'viabackwards',
            'simplevoicechatbukkit' => 'voicechat', 'simplevoicechat' => 'voicechat',
            'worldeditbukkit' => 'worldedit', 'worldguardbukkit' => 'worldguard',
        ];

        return $aliases[trim($name)] ?? trim($name);
    }

    private function cleanLoadedVersion(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/-SNAPSHOT(?:\s*\([^)]*\))?$/', '', $version) ?? $version;
        $version = preg_replace('/\s*\([^)]*\)$/', '', $version) ?? $version;

        return trim($version);
    }

    /** @return array<string, mixed>|null */
    private function candidateFromLatestUpdateCheck(MinecraftToolkitPackage $package): ?array
    {
        if (! Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json')) {
            return null;
        }

        $check = MinecraftToolkitUpdateCheck::query()
            ->where('server_uuid', $package->server_uuid)
            ->where('package_id', $package->id)
            ->where('status', 'update_available')
            ->latest('id')
            ->first();

        if (! $check instanceof MinecraftToolkitUpdateCheck || ! is_array($check->candidate_json)) {
            return null;
        }

        $candidate = $check->candidate_json;
        foreach (['version_id', 'version_number', 'file_name', 'url', 'hashes', 'dependencies'] as $key) {
            if (! array_key_exists($key, $candidate)) {
                return null;
            }
        }

        // Only reuse the exact candidate from the latest check when it still targets the
        // currently installed package version. This avoids installing a stale candidate
        // after a manual file replacement.
        if ((string) ($check->old_version_id ?? '') !== (string) ($package->source_version_id ?? '')
            && ! $this->versionsEquivalent((string) ($check->old_version_number ?? ''), (string) ($package->version_number ?? ''))) {
            return null;
        }

        return $candidate;
    }

    /** @param array<string, mixed> $candidate
     * @param  array{sha1: string, sha256: string, sha512: string, size: int, plugin_version: ?string}  $metadata
     */
    private function assertDownloadedPackageMatchesCandidate(
        MinecraftToolkitPackage $package,
        array $candidate,
        array $metadata
    ): void {
        $downloadedVersion = $metadata['plugin_version'] ?? null;
        if (! is_string($downloadedVersion) || $downloadedVersion === '') {
            return;
        }

        if ($package->source === 'geysermc') {
            return;
        }

        $expected = (string) ($candidate['version_number'] ?? '');
        if ($expected === '') {
            return;
        }

        if (! $this->versionsEquivalent($downloadedVersion, $expected)) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.downloaded_version_mismatch_update', ['downloaded' => $downloadedVersion, 'expected' => $expected])
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertStoredPackageHashesMatch(MinecraftToolkitPackage $package, array $metadata): void
    {
        $storedSha512 = trim((string) ($package->sha512 ?? ''));
        if ($storedSha512 !== ''
            && ! hash_equals(strtolower($storedSha512), strtolower((string) ($metadata['sha512'] ?? '')))) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.stored_sha512_mismatch')
            );
        }

        $storedSha1 = trim((string) ($package->sha1 ?? ''));
        if ($storedSha1 !== ''
            && ! hash_equals(strtolower($storedSha1), strtolower((string) ($metadata['sha1'] ?? '')))) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.stored_sha1_mismatch')
            );
        }
    }

    /** @return Collection<int, MinecraftToolkitPackage> */
    private function managedPackages(Server $server)
    {
        return MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('managed', true)
            ->where('enabled', true)
            ->whereIn('package_type', ['server_jar', 'server_binary', 'plugin', 'mod', 'crossplay', 'dependency'])
            ->orderBy('project_name')
            ->get();
    }

    /** @param array<string, mixed>|null $candidate */
    private function storeCheck(
        MinecraftToolkitPackage $package,
        string $status,
        string $message,
        ?array $candidate = null
    ): MinecraftToolkitUpdateCheck {
        $package->forceFill(['last_checked_at' => now()])->save();

        return MinecraftToolkitUpdateCheck::query()->create([
            'server_uuid' => $package->server_uuid,
            'package_id' => $package->id,
            'old_version_id' => $package->source_version_id,
            'new_version_id' => $candidate['version_id'] ?? null,
            'old_version_number' => $package->version_number,
            'new_version_number' => $candidate['version_number'] ?? null,
            'status' => $status,
            'message' => $message,
            'candidate_json' => Schema::hasColumn('minecraft_toolkit_update_checks', 'candidate_json') ? $candidate : null,
            'checked_at' => now(),
        ]);
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
            Log::warning('Minecraft Toolkit could not persist an update log.', ['exception' => $exception]);
        }
    }
}
