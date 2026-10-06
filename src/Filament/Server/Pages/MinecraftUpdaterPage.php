<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitUpdateCheck;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftRiskGateService;
use BlueWolf\MinecraftToolkit\Services\MinecraftUpdateService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnitEnum;

class MinecraftUpdaterPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-refresh';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'minecraft-updater';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-updater';

    /** @var array<int, array<string, mixed>> */
    public array $packages = [];

    /** @var array<int, array<string, mixed>> */
    public array $history = [];

    /** @var array<int, string> */
    public array $packageNotes = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->refreshPackages();
    }

    public static function canAccess(): bool
    {
        if (! (bool) config('minecrafttoolkit.enabled', true)
            || ! (bool) config('minecrafttoolkit.updater_enabled', true)
            || ! Schema::hasTable('minecraft_toolkit_setups')
            || ! Schema::hasTable('minecraft_toolkit_packages')
            || ! Schema::hasTable('minecraft_toolkit_update_checks')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && app(MinecraftPermissionService::class)->canModify($user, $server)
            && MinecraftToolkitSetup::query()
                ->where('server_uuid', $server->uuid)
                ->where('setup_status', 'completed')
                ->exists();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecrafttoolkit::strings.navigation.updater');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.updater');
    }

    public function checkUpdates(): void
    {
        try {
            $checks = app(MinecraftUpdateService::class)->checkAll($this->server(), $this->setup());
            $available = collect($checks)->where('status', 'update_available')->count();

            $notification = Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.check_complete'))
                ->body($available === 1
                    ? trans('minecrafttoolkit::strings.updater.one_update_available')
                    : trans('minecrafttoolkit::strings.updater.many_updates_available', ['count' => $available]))
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.check_failed'), $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.check_failed'));
        }
    }

    public function updatePackage(int $packageId): void
    {
        try {
            $package = app(MinecraftUpdateService::class)
                ->updatePackage($this->server(), $this->setup(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.package_updated', ['name' => $package->project_name]))
                ->body(trans('minecrafttoolkit::strings.updater.installed_version_body', ['version' => $package->version_number]))
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.update_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.update_failed'));
            $this->refreshPackages();
        }
    }

    public function installDependencies(int $packageId): void
    {
        try {
            $result = app(MinecraftUpdateService::class)
                ->installMissingDependencies($this->server(), $this->setup(), $packageId);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.dependencies_installed'))
                ->body(trans('minecrafttoolkit::strings.updater.dependencies_body', [
                    'installed' => $result['installed'],
                    'skipped' => $result['skipped'],
                    'errors' => count($result['errors']),
                ]))
                ->status(count($result['errors']) > 0 ? 'warning' : 'success');
            if (count($result['errors']) > 0) {
                $notification->persistent();
            }
            $notification->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.dependencies_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.dependencies_failed'));
            $this->refreshPackages();
        }
    }

    public function deletePackage(int $packageId): void
    {
        try {
            app(MinecraftRiskGateService::class)->assertAllowed('package_removal', $this->server());
            app(MinecraftUpdateService::class)->deletePackage($this->server(), $packageId);
            $notification = Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.package_deleted'))
                ->body(trans('minecrafttoolkit::strings.updater.package_deleted_body'))
                ->warning()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.delete_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.delete_failed'));
            $this->refreshPackages();
        }
    }

    public function updateAll(): void
    {
        try {
            $result = app(MinecraftUpdateService::class)->updateAll($this->server(), $this->setup());
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.updates_complete'))
                ->body(trans('minecrafttoolkit::strings.updater.update_all_body', [
                    'updated' => $result['updated'],
                    'failed' => $result['failed'],
                    'skipped' => $result['skipped_pinned'],
                ]))
                ->status($result['failed'] > 0 ? 'warning' : 'success');
            if ($result['failed'] > 0) {
                $notification->persistent();
            }
            $notification->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.update_all_failed'), $exception);
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.update_all_failed'));
        }
    }

    public function pinPackage(int $packageId): void
    {
        $this->setPackagePinned($packageId, true);
    }

    public function unpinPackage(int $packageId): void
    {
        $this->setPackagePinned($packageId, false);
    }

    public function pinAllPackages(): void
    {
        $count = $this->setAllPackagesPinned(true);

        Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_pin_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_changed', ['count' => $count]))
            ->success()
            ->send();
    }

    public function unpinAllPackages(): void
    {
        $count = $this->setAllPackagesPinned(false);

        Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_unpin_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_changed', ['count' => $count]))
            ->success()
            ->send();
    }

    public function verifyPackage(int $packageId): void
    {
        try {
            $result = app(MinecraftUpdateService::class)->verifyPackage($this->server(), $packageId);
            $notification = Notification::make()
                ->title(trans('minecrafttoolkit::strings.updater.verify_complete'))
                ->body($result['message'])
                ->status($result['status'] === 'verified' ? 'success' : 'danger');
            if ($result['status'] !== 'verified') {
                $notification->persistent();
            }
            $notification->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.verify_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.verify_failed'));
            $this->refreshPackages();
        }
    }

    public function verifyAllPackages(): void
    {
        $verified = 0;
        $failed = 0;

        foreach ($this->packages as $package) {
            try {
                $result = app(MinecraftUpdateService::class)->verifyPackage($this->server(), (int) $package['id']);
                if (($result['status'] ?? null) === 'verified') {
                    $verified++;
                } else {
                    $failed++;
                }
            } catch (MinecraftToolkitException $exception) {
                $failed++;
            } catch (\Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $notification = Notification::make()
            ->title(trans('minecrafttoolkit::strings.updater.bulk_verify_complete'))
            ->body(trans('minecrafttoolkit::strings.updater.bulk_verify_body', [
                'verified' => $verified,
                'failed' => $failed,
            ]))
            ->status($failed > 0 ? 'warning' : 'success');
        if ($failed > 0) {
            $notification->persistent();
        }
        $notification->send();

        $this->refreshPackages();
    }

    public function togglePackage(int $packageId, bool $enabled): void
    {
        try {
            app(MinecraftUpdateService::class)->setPackageEnabled($this->server(), $packageId, $enabled);
            Notification::make()->success()->title($enabled
                ? trans('minecrafttoolkit::strings.updater.package_enabled')
                : trans('minecrafttoolkit::strings.updater.package_disabled'))->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.toggle_failed'), $exception);
        }
    }

    public function reinstallPackage(int $packageId): void
    {
        try {
            $package = app(MinecraftUpdateService::class)->reinstallPackage($this->server(), $this->setup(), $packageId);
            Notification::make()->success()->title(trans('minecrafttoolkit::strings.updater.package_reinstalled', ['name' => $package->project_name]))->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.reinstall_failed'), $exception);
        }
    }

    public function disableAllPackages(): void
    {
        $changed = 0;
        foreach ($this->packages as $package) {
            if ($package['enabled'] && $package['can_disable']) {
                try {
                    app(MinecraftUpdateService::class)->setPackageEnabled($this->server(), (int) $package['id'], false);
                    $changed++;
                } catch (MinecraftToolkitException) {
                }
            }
        }
        Notification::make()->success()->title(trans('minecrafttoolkit::strings.updater.bulk_disable_complete', ['count' => $changed]))->send();
        $this->refreshPackages();
    }

    public function savePackageNote(int $packageId): void
    {
        abort_unless(user()?->isRootAdmin(), 403);
        $package = MinecraftToolkitPackage::query()->whereKey($packageId)->where('server_uuid', $this->server()->uuid)->firstOrFail();
        $package->update(['admin_notes' => trim((string) ($this->packageNotes[$packageId] ?? '')) ?: null]);
        Notification::make()->success()->title(trans('minecrafttoolkit::strings.updater.note_saved'))->send();
        $this->refreshPackages();
    }

    private function setPackagePinned(int $packageId, bool $pinned): void
    {
        try {
            app(MinecraftUpdateService::class)->setPackagePinned($this->server(), $packageId, $pinned);
            Notification::make()
                ->title($pinned
                    ? trans('minecrafttoolkit::strings.updater.package_pinned')
                    : trans('minecrafttoolkit::strings.updater.package_unpinned'))
                ->success()
                ->send();
            $this->refreshPackages();
        } catch (MinecraftToolkitException $exception) {
            $this->notifyError(trans('minecrafttoolkit::strings.updater.pin_failed'), $exception);
            $this->refreshPackages();
        } catch (\Throwable $exception) {
            report($exception);
            $this->notifyUnexpectedError(trans('minecrafttoolkit::strings.updater.pin_failed'));
            $this->refreshPackages();
        }
    }

    private function setAllPackagesPinned(bool $pinned): int
    {
        $count = MinecraftToolkitPackage::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('managed', true)
            ->whereIn('package_type', ['server_jar', 'server_binary', 'plugin', 'mod', 'crossplay', 'dependency'])
            ->update(['update_pinned' => $pinned]);

        $this->refreshPackages();

        return $count;
    }

    private function refreshPackages(): void
    {
        $this->packages = MinecraftToolkitPackage::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('managed', true)
            ->whereIn('package_type', ['server_jar', 'server_binary', 'plugin', 'mod', 'crossplay', 'dependency'])
            ->orderByDesc('is_system_package')
            ->orderBy('project_name')
            ->get()
            ->map(function (MinecraftToolkitPackage $package): array {
                $check = MinecraftToolkitUpdateCheck::query()
                    ->where('package_id', $package->id)
                    ->latest('id')
                    ->first();
                $installedAt = $package->getAttribute('installed_at');
                $verifiedAfterInstall = MinecraftToolkitUpdateCheck::query()
                    ->where('package_id', $package->id)
                    ->where('status', 'verified')
                    ->when(
                        $installedAt instanceof \DateTimeInterface,
                        fn ($query) => $query->where('checked_at', '>=', $installedAt)
                    )
                    ->exists();

                return [
                    'id' => $package->id,
                    'name' => $package->project_name,
                    'source' => $package->source,
                    'type' => $package->package_type,
                    'project_id' => $package->source_project_id,
                    'version_id' => $package->source_version_id,
                    'current_version' => $package->version_number,
                    'minecraft_version' => $package->minecraft_version,
                    'loader' => $package->loader,
                    'file_name' => $package->file_name,
                    'file_path' => $package->file_path,
                    'sha1' => $package->sha1,
                    'sha512' => $package->sha512,
                    'dependencies_count' => is_array($package->dependencies_json) ? count($package->dependencies_json) : 0,
                    'installed_at' => $package->installed_at?->diffForHumans(),
                    'new_version' => $check?->new_version_number,
                    'status' => $check?->status ?? 'unchecked',
                    'message' => $check?->message,
                    'checked_at' => $check?->checked_at?->diffForHumans(),
                    'system' => $package->is_system_package,
                    'pinned' => (bool) $package->update_pinned,
                    'enabled' => (bool) $package->enabled,
                    'health' => $this->packageHealth($package, $check, $verifiedAfterInstall),
                    'can_install_dependencies' => $check?->status === 'error'
                        && is_string($check?->message)
                        && Str::contains($check->message, ['Pflicht-Abhängigkeiten', 'Fehlende Plugin-Abhängigkeit', 'Required dependencies', 'Missing plugin dependency']),
                    'can_delete' => ! $package->is_system_package,
                    'can_disable' => ! in_array($package->package_type, ['server_jar', 'server_binary'], true),
                    'admin_notes' => $package->admin_notes,
                ];
            })
            ->all();

        $this->packageNotes = collect($this->packages)->mapWithKeys(fn (array $package): array => [(int) $package['id'] => (string) ($package['admin_notes'] ?? '')])->all();

        $this->history = MinecraftToolkitUpdateCheck::query()
            ->with('package')
            ->where('server_uuid', $this->server()->uuid)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (MinecraftToolkitUpdateCheck $check): array => [
                'package' => $check->package?->project_name ?? trans('minecrafttoolkit::strings.updater.deleted_package'),
                'status' => $check->status,
                'old_version' => $check->old_version_number,
                'new_version' => $check->new_version_number,
                'message' => $check->message,
                'checked_at' => $check->checked_at?->format('d.m.Y H:i'),
            ])
            ->all();
    }

    /** @return array{score: int, label: string, color: string, reasons: array<int, string>} */
    private function packageHealth(
        MinecraftToolkitPackage $package,
        ?MinecraftToolkitUpdateCheck $check,
        bool $verifiedAfterInstall = false,
    ): array {
        $score = 75;
        $reasons = [];
        $packageMetadata = $package->getAttribute('dependencies_json');

        if (in_array($package->source, [
            'modrinth',
            'geysermc',
            'official',
            'official-bedrock',
            'paper',
            'purpur',
            'folia',
            'fabric',
            'forge',
            'neoforge',
        ], true)) {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_trusted_source');
        } elseif ($package->source === 'curseforge') {
            $score += 5;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_known_source');
        }

        if (is_string($package->sha512) && $package->sha512 !== '') {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_sha512');
        } elseif (is_array($packageMetadata)
            && is_string($packageMetadata['sha256'] ?? null)
            && $packageMetadata['sha256'] !== '') {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_sha256');
        } elseif (is_string($package->sha1) && $package->sha1 !== '') {
            $score += 3;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_sha1');
        } else {
            $score -= 20;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_no_hash');
        }

        if ($package->update_pinned) {
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_pinned');
        }

        if ($verifiedAfterInstall || $check?->status === 'verified') {
            $score += 10;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_verified');
        } elseif (in_array($check?->status, ['error', 'rollback_recommended'], true)) {
            $score -= 25;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_error');
        } elseif ($check?->status === 'update_available') {
            $score -= 5;
            $reasons[] = trans('minecrafttoolkit::strings.updater.health_update_available');
        }

        $score = min(100, max(0, $score));

        return [
            'score' => $score,
            'label' => $score >= 85
                ? trans('minecrafttoolkit::strings.updater.health_good')
                : ($score >= 60
                    ? trans('minecrafttoolkit::strings.updater.health_ok')
                    : trans('minecrafttoolkit::strings.updater.health_attention')),
            'color' => $score >= 85 ? 'success' : ($score >= 60 ? 'warning' : 'danger'),
            'reasons' => array_slice($reasons, 0, 3),
        ];
    }

    private function setup(): MinecraftToolkitSetup
    {
        return MinecraftToolkitSetup::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('setup_status', 'completed')
            ->firstOrFail();
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function notifyError(string $title, MinecraftToolkitException $exception): void
    {
        Notification::make()
            ->title($title)
            ->body($this->localizedExceptionBody($exception))
            ->danger()
            ->persistent()
            ->send();
    }

    private function notifyUnexpectedError(string $title): void
    {
        Notification::make()
            ->title($title)
            ->body(trans('minecrafttoolkit::strings.common.unexpected_error_body'))
            ->danger()
            ->persistent()
            ->send();
    }

    private function localizedExceptionBody(MinecraftToolkitException $exception): string
    {
        return str_starts_with(strtolower((string) app()->getLocale()), 'de')
            ? $exception->getMessage()
            : trans('minecrafttoolkit::strings.common.action_failed_body');
    }
}
