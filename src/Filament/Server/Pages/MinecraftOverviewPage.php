<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\CurseForgeService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerStateService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class MinecraftOverviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-brand-minecraft';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'minecraft-overview';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-overview';

    public ?MinecraftToolkitSetup $setup = null;

    public array $logs = [];

    public array $backups = [];

    public array $sourceStatuses = [];

    public array $adminChecklist = [];

    public int $packageCount = 0;

    public function mount(): void
    {
        $this->authorizeAccess();
        /** @var Server $server */
        $server = Filament::getTenant();
        $this->setup = MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->first();
        $this->packageCount = Schema::hasTable('minecraft_toolkit_packages')
            ? MinecraftToolkitPackage::query()
                ->where('server_uuid', $server->uuid)
                ->whereIn('package_type', ['plugin', 'mod'])
                ->where('enabled', true)
                ->count()
            : 0;
        $this->logs = Schema::hasTable('minecraft_toolkit_logs')
            ? MinecraftToolkitLog::query()
                ->where('server_uuid', $server->uuid)
                ->latest('id')
                ->limit(10)
                ->get()
                ->all()
            : [];
        $this->backups = $this->backupInventory($server);
        $this->sourceStatuses = [
            [
                'name' => 'Modrinth',
                'enabled' => (bool) config('minecrafttoolkit.modrinth_enabled', true),
                'detail' => (bool) config('minecrafttoolkit.modrinth_enabled', true) ? trans('minecrafttoolkit::strings.overview.enabled') : trans('minecrafttoolkit::strings.overview.disabled'),
            ],
            [
                'name' => 'CurseForge',
                'enabled' => app(CurseForgeService::class)->isConfigured(),
                'detail' => app(CurseForgeService::class)->keySource() ?? trans('minecrafttoolkit::strings.overview.not_configured'),
            ],
            [
                'name' => 'Crossplay',
                'enabled' => (bool) config('minecrafttoolkit.crossplay_enabled', true),
                'detail' => (bool) config('minecrafttoolkit.crossplay_enabled', true) ? trans('minecrafttoolkit::strings.overview.enabled') : trans('minecrafttoolkit::strings.overview.disabled'),
            ],
        ];
        $this->adminChecklist = $this->adminChecklist();
    }

    public static function canAccess(): bool
    {
        if (! (bool) config('minecrafttoolkit.enabled', true)
            || ! Schema::hasTable('minecraft_toolkit_setups')) {
            return false;
        }

        $server = Filament::getTenant();
        $user = user();

        return $server instanceof Server
            && $user !== null
            && app(MinecraftPermissionService::class)->canView($user, $server)
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
        return trans('minecrafttoolkit::strings.navigation.overview');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.overview');
    }

    public function restoreBackupFile(string $backupPath, string $fileName, string $targetPath): void
    {
        try {
            if ($targetPath === '') {
                throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.overview.restore_target_missing'));
            }

            $server = $this->server();
            app(MinecraftServerStateService::class)->assertOffline($server);
            $currentBackup = app(MinecraftServerFileService::class)->restoreBackupFile(
                $server,
                $backupPath,
                $fileName,
                $targetPath
            );

            MinecraftToolkitLog::query()->create([
                'server_uuid' => $server->uuid,
                'user_id' => user()?->id,
                'action' => 'backup_restored',
                'level' => 'warning',
                'message' => trans('minecrafttoolkit::strings.messages.backup_restored', ['file' => $fileName, 'path' => $targetPath]),
                'context_json' => [
                    'backup_path' => $backupPath,
                    'file_name' => $fileName,
                    'target_path' => $targetPath,
                    'current_backup' => $currentBackup,
                ],
            ]);

            Notification::make()
                ->title(trans('minecrafttoolkit::strings.overview.restore_complete'))
                ->body(trans('minecrafttoolkit::strings.overview.restore_complete_body', ['target' => $targetPath]))
                ->warning()
                ->send();

            $this->backups = $this->backupInventory($server);
            $this->logs = MinecraftToolkitLog::query()
                ->where('server_uuid', $server->uuid)
                ->latest('id')
                ->limit(10)
                ->get()
                ->all();
        } catch (MinecraftToolkitException $exception) {
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.overview.restore_failed'))
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.overview.restore_failed'))
                ->body(trans('minecrafttoolkit::strings.overview.restore_failed_body'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /** @return array<int, array<string, mixed>> */
    private function backupInventory(Server $server): array
    {
        $targets = $this->restoreTargetMap($server);

        return collect(app(MinecraftServerFileService::class)->listBackups($server))
            ->map(function (array $backup) use ($targets): array {
                $backup['files'] = collect($backup['files'] ?? [])
                    ->map(function (array $file) use ($targets): array {
                        $file['restore_path'] = $targets[$file['name']] ?? null;

                        return $file;
                    })
                    ->all();

                return $backup;
            })
            ->all();
    }

    /** @return array<string, string> */
    private function restoreTargetMap(Server $server): array
    {
        $targets = [
            'server.jar' => '/server.jar',
            'server.properties' => '/server.properties',
            'server-icon.png' => '/server-icon.png',
            'bedrock-server.zip' => '/bedrock-server.zip',
            'run.sh' => '/run.sh',
            'forge-installer.jar' => '/forge-installer.jar',
            'neoforge-installer.jar' => '/neoforge-installer.jar',
        ];

        if (Schema::hasTable('minecraft_toolkit_packages')) {
            MinecraftToolkitPackage::query()
                ->where('server_uuid', $server->uuid)
                ->whereNotNull('file_name')
                ->whereNotNull('file_path')
                ->get()
                ->each(function (MinecraftToolkitPackage $package) use (&$targets): void {
                    if (is_string($package->file_name) && is_string($package->file_path)) {
                        $targets[$package->file_name] = $package->file_path;
                    }
                });
        }

        return $targets;
    }

    /** @return array<int, array{label: string, ok: bool, detail: string}> */
    private function adminChecklist(): array
    {
        return [
            [
                'label' => trans('minecrafttoolkit::strings.overview.check_backups'),
                'ok' => (bool) config('minecrafttoolkit.backup_before_overwrite', true),
                'detail' => (bool) config('minecrafttoolkit.backup_before_overwrite', true)
                    ? trans('minecrafttoolkit::strings.overview.check_ok')
                    : trans('minecrafttoolkit::strings.overview.check_backups_warning'),
            ],
            [
                'label' => trans('minecrafttoolkit::strings.overview.check_audit'),
                'ok' => (bool) config('minecrafttoolkit.security_audit_log_enabled', true),
                'detail' => (bool) config('minecrafttoolkit.security_audit_log_enabled', true)
                    ? trans('minecrafttoolkit::strings.overview.check_ok')
                    : trans('minecrafttoolkit::strings.overview.check_audit_warning'),
            ],
            [
                'label' => trans('minecrafttoolkit::strings.overview.check_curseforge'),
                'ok' => ! (bool) config('minecrafttoolkit.curseforge_enabled', true)
                    || ((bool) config('minecrafttoolkit.curseforge_proxy_signed_requests', true)
                        && app(CurseForgeService::class)->isConfigured()),
                'detail' => ! (bool) config('minecrafttoolkit.curseforge_enabled', true)
                    ? trans('minecrafttoolkit::strings.overview.check_disabled')
                    : (((bool) config('minecrafttoolkit.curseforge_proxy_signed_requests', true)
                        && app(CurseForgeService::class)->isConfigured())
                        ? trans('minecrafttoolkit::strings.overview.check_ok')
                        : trans('minecrafttoolkit::strings.overview.check_curseforge_warning')),
            ],
            [
                'label' => trans('minecrafttoolkit::strings.overview.check_hashes'),
                'ok' => (bool) config('minecrafttoolkit.hash_required', false),
                'detail' => (bool) config('minecrafttoolkit.hash_required', false)
                    ? trans('minecrafttoolkit::strings.overview.check_strict')
                    : trans('minecrafttoolkit::strings.overview.check_hashes_warning'),
            ],
            [
                'label' => trans('minecrafttoolkit::strings.overview.check_risk_gates'),
                'ok' => collect([
                    'risk_gate_startup_edits_admin_only',
                    'risk_gate_version_risk_admin_only',
                    'risk_gate_package_removal_admin_only',
                    'risk_gate_curseforge_usage_admin_only',
                    'risk_gate_crossplay_setup_admin_only',
                    'risk_gate_raw_properties_admin_only',
                ])->contains(fn (string $key): bool => (bool) config("minecrafttoolkit.$key", false)),
                'detail' => trans('minecrafttoolkit::strings.overview.check_risk_gates_detail'),
            ],
        ];
    }
}
