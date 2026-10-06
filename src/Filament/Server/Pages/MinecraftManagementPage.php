<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftConflictService;
use BlueWolf\MinecraftToolkit\Services\MinecraftConversionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftManagementService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/** @property \Filament\Schemas\Schema $form */
class MinecraftManagementPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-tool';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 24;

    protected static ?string $slug = 'minecraft-management';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-management';

    public ?array $data = [];

    public array $diagnostics = [];

    public array $conflicts = [];

    public array $worldBackups = [];

    public array $worldInfo = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $setup = $this->setup();
        $this->form->fill(['access' => app(MinecraftManagementService::class)->accessFiles($this->server(), $setup->edition)]);
        $this->worldBackups = app(MinecraftManagementService::class)->worldBackups($this->server());
        $this->worldInfo = app(MinecraftManagementService::class)->worldInfo($this->server(), $setup->level_name);
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return Schema::hasTable('minecraft_toolkit_setups') && $server instanceof Server && user() !== null
            && app(MinecraftPermissionService::class)->canModify(user(), $server)
            && MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->where('setup_status', 'completed')->exists();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecrafttoolkit::strings.management.title');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.management.title');
    }

    protected function getFormSchema(): array
    {
        $files = $this->setup()->edition === 'bedrock'
            ? ['allowlist.json', 'permissions.json']
            : ['whitelist.json', 'ops.json', 'banned-players.json', 'banned-ips.json'];

        return [Section::make(trans('minecrafttoolkit::strings.management.access'))->schema(
            collect($files)->map(fn (string $file) => Textarea::make('access.'.$file)->label($file)->rows(8)->required())->all()
        )->columns(2)];
    }

    public function saveAccess(): void
    {
        try {
            app(MinecraftManagementService::class)->saveAccessFiles($this->server(), (array) ($this->form->getState()['access'] ?? []));
            Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.saved'))->send();
        } catch (MinecraftToolkitException $exception) {
            Notification::make()->danger()->title(trans('minecrafttoolkit::strings.management.failed'))->body($exception->getMessage())->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rename_world')->label(trans('minecrafttoolkit::strings.management.rename_world'))->schema([
                TextInput::make('old_name')->default($this->setup()->level_name)->required(), TextInput::make('new_name')->required(),
            ])->action(function (array $data): void {
                app(MinecraftManagementService::class)->renameWorld($this->server(), (string) $data['old_name'], (string) $data['new_name']);
                $this->setup()->update(['level_name' => $data['new_name']]);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.world_renamed'))->send();
            }),
            Action::make('datapack')->label(trans('minecrafttoolkit::strings.management.install_datapack'))->schema([
                TextInput::make('world')->default($this->setup()->level_name)->required(),
                FileUpload::make('file')->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])->maxSize(102400)->required(),
            ])->action(function (array $data): void {
                [$name, $contents] = $this->uploadedFile($data['file']);
                app(MinecraftManagementService::class)->installDatapack($this->server(), (string) $data['world'], $name, $contents);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.datapack_installed'))->send();
            }),
            Action::make('resource_pack')->label(trans('minecrafttoolkit::strings.management.resource_pack'))->schema([
                TextInput::make('url')->url()->required(), Toggle::make('required')->default(false), TextInput::make('prompt')->maxLength(255),
            ])->action(function (array $data): void {
                $hash = app(MinecraftManagementService::class)->configureResourcePack($this->server(), (string) $data['url'], (bool) ($data['required'] ?? false), (string) ($data['prompt'] ?? ''));
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.resource_pack_saved'))->body('SHA-1: '.$hash)->send();
            }),
            Action::make('icon')->label(trans('minecrafttoolkit::strings.management.server_icon'))->schema([
                FileUpload::make('file')->image()->maxSize(2048)->required(),
            ])->action(function (array $data): void {
                [, $contents] = $this->uploadedFile($data['file']);
                app(MinecraftManagementService::class)->writeServerIcon($this->server(), $contents);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.icon_saved'))->send();
            }),
            Action::make('performance')->label(trans('minecrafttoolkit::strings.management.performance'))->visible(fn () => in_array($this->setup()->software, ['paper', 'purpur', 'folia'], true))->schema([
                Select::make('preset')->options([
                    'performance' => trans('minecrafttoolkit::strings.management.preset_performance'),
                    'high_performance' => trans('minecrafttoolkit::strings.management.preset_high_performance'),
                    'quality' => trans('minecrafttoolkit::strings.management.preset_quality'),
                ])->required(),
            ])->action(function (array $data): void {
                app(MinecraftManagementService::class)->applyPerformancePreset($this->server(), (string) $data['preset']);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.performance_saved'))->send();
            }),
            Action::make('geyser')->label(trans('minecrafttoolkit::strings.management.geyser_diagnostics'))->visible(fn () => $this->setup()->geyser_enabled)->action(function (): void {
                $this->diagnostics = app(MinecraftManagementService::class)->geyserDiagnostics($this->server(), (int) $this->setup()->bedrock_allocation_port);
            }),
            Action::make('convert')->label(trans('minecrafttoolkit::strings.management.convert'))->visible(fn () => app(MinecraftConversionService::class)->targets($this->setup()) !== [])->schema([
                Select::make('target')->options(fn () => collect(app(MinecraftConversionService::class)->targets($this->setup()))->mapWithKeys(fn (string $target): array => [$target => ucfirst($target)])->all())->required(),
                Toggle::make('confirm')->label(trans('minecrafttoolkit::strings.management.convert_confirm'))->accepted()->required(),
            ])->action(function (array $data): void {
                app(MinecraftConversionService::class)->convert($this->server(), $this->setup(), (string) $data['target']);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.converted'))->send();
            }),
            Action::make('conflicts')->label(trans('minecrafttoolkit::strings.management.conflicts'))->action(function (): void {
                $this->conflicts = app(MinecraftConflictService::class)->warnings($this->setup(), MinecraftToolkitPackage::query()->where('server_uuid', $this->server()->uuid)->where('managed', true)->get());
            }),
            Action::make('backup_world')->label(trans('minecrafttoolkit::strings.management.backup_world'))->schema([TextInput::make('world')->default($this->setup()->level_name)->required()])->action(function (array $data): void {
                app(MinecraftManagementService::class)->backupWorld($this->server(), (string) $data['world']);
                $this->worldBackups = app(MinecraftManagementService::class)->worldBackups($this->server());
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.world_backed_up'))->send();
            }),
            Action::make('restore_world')->label(trans('minecrafttoolkit::strings.management.restore_world'))->visible(fn () => $this->worldBackups !== [])->schema([
                Select::make('archive')->options(fn () => array_combine($this->worldBackups, $this->worldBackups))->required(), TextInput::make('world')->default($this->setup()->level_name)->required(), Toggle::make('confirm')->accepted()->required(),
            ])->action(function (array $data): void {
                app(MinecraftManagementService::class)->restoreWorld($this->server(), (string) $data['archive'], (string) $data['world']);
                Notification::make()->success()->title(trans('minecrafttoolkit::strings.management.world_restored'))->send();
            }),
        ];
    }

    /** @return array{string, string} */
    private function uploadedFile(mixed $value): array
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($value instanceof TemporaryUploadedFile) {
            return [$value->getClientOriginalName(), (string) file_get_contents($value->getRealPath())];
        }
        $path = is_string($value) ? storage_path('app/'.ltrim($value, '/')) : '';
        if ($path === '' || ! is_file($path)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.upload_not_found'));
        }

        return [basename($path), (string) file_get_contents($path)];
    }

    private function server(): Server
    { /** @var Server $server */ $server = Filament::getTenant();

        return $server;
    }

    private function setup(): MinecraftToolkitSetup
    {
        return MinecraftToolkitSetup::query()->where('server_uuid', $this->server()->uuid)->firstOrFail();
    }
}
