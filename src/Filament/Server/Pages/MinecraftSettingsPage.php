<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftCrossplayService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftPropertiesService;
use BlueWolf\MinecraftToolkit\Services\MinecraftRiskGateService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerFileService;
use BlueWolf\MinecraftToolkit\Services\MinecraftServerStateService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

/**
 * @property \Filament\Schemas\Schema $form
 */
class MinecraftSettingsPage extends Page implements HasSchemas
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'tabler-settings';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 23;

    protected static ?string $slug = 'minecraft-settings';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        /** @var Server $server */
        $server = Filament::getTenant();
        $setup = MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->firstOrFail();
        $properties = '';
        try {
            $properties = app(MinecraftServerFileService::class)->read($server, '/server.properties', 1048576);
        } catch (\Throwable) {
            $properties = '';
        }

        $parsedProperties = app(MinecraftPropertiesService::class)->parse($properties);

        $this->form->fill($setup->only([
            'motd',
            'max_players',
            'online_mode',
            'whitelist',
            'pvp',
            'allow_flight',
            'view_distance',
            'simulation_distance',
        ]) + [
            'properties_page' => 'basic',
            'properties' => $this->defaultsForKnownProperties($parsedProperties),
            'server_properties_raw' => $properties,
            'bedrock_allocation_id' => $this->currentBedrockAllocationId($server, $setup),
        ]);
    }

    public static function canAccess(): bool
    {
        if (! Schema::hasTable('minecraft_toolkit_setups')) {
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
        return trans('minecrafttoolkit::strings.navigation.settings');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.navigation.settings');
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Section::make('server.properties')
                ->description(trans('minecrafttoolkit::strings.settings_page.description'))
                ->columns(2)
                ->schema([
                    TextInput::make('motd')->label('MOTD')->required()->maxLength(255),
                    TextInput::make('max_players')->label(trans('minecrafttoolkit::strings.setup.max_players'))->numeric()->minValue(1)->required(),
                    TextInput::make('view_distance')->label(trans('minecrafttoolkit::strings.setup.view_distance'))->numeric()->minValue(2)->maxValue(32)->required(),
                    TextInput::make('simulation_distance')->label(trans('minecrafttoolkit::strings.setup.simulation_distance'))->numeric()->minValue(2)->maxValue(32)->required(),
                    Toggle::make('online_mode')->label('Online Mode'),
                    Toggle::make('whitelist')->label('Whitelist'),
                    Toggle::make('pvp')->label('PVP'),
                    Toggle::make('allow_flight')->label(trans('minecrafttoolkit::strings.setup.allow_flight')),
                ]),
            Section::make(trans('minecrafttoolkit::strings.settings_page.properties_all_values'))
                ->description(trans('minecrafttoolkit::strings.settings_page.properties_all_values_desc'))
                ->columns(2)
                ->schema([
                    Select::make('properties_page')
                        ->label(trans('minecrafttoolkit::strings.settings_page.properties_page'))
                        ->options($this->propertyPageOptions())
                        ->default('basic')
                        ->live()
                        ->columnSpanFull(),
                    ...$this->propertyPageFields(),
                    Textarea::make('server_properties_raw')
                        ->label(trans('minecrafttoolkit::strings.settings_page.properties_raw'))
                        ->rows(18)
                        ->columnSpanFull()
                        ->helperText(trans('minecrafttoolkit::strings.settings_page.properties_raw_help')),
                ]),
            Section::make(trans('minecrafttoolkit::strings.settings_page.crossplay'))
                ->description(trans('minecrafttoolkit::strings.settings_page.crossplay_desc'))
                ->visible(fn (): bool => $this->supportsCrossplay())
                ->schema([
                    Select::make('bedrock_allocation_id')
                        ->label(trans('minecrafttoolkit::strings.setup.bedrock_allocation'))
                        ->options(fn (): array => $this->bedrockAllocationOptions()),
                ]),
        ];
    }

    /** @return array<string, string> */
    private function propertyPageOptions(): array
    {
        return [
            'basic' => '1/4 Basis & Welt',
            'access' => '2/4 Zugriff & Sicherheit',
            'performance' => '3/4 Performance & Netzwerk',
            'resource' => '4/4 Resource-Pack & Erweitert',
        ];
    }

    /** @return array<int, mixed> */
    private function propertyPageFields(): array
    {
        $fields = [];
        foreach ($this->knownPropertyDefinitions() as $definition) {
            $name = 'properties.'.$this->propertyFormKey($definition['key']);
            $field = match ($definition['type']) {
                'bool' => Toggle::make($name)->label($definition['label']),
                'select' => Select::make($name)->label($definition['label'])->options($definition['options']),
                'int' => TextInput::make($name)->label($definition['label'])->numeric(),
                default => TextInput::make($name)->label($definition['label']),
            };

            $fields[] = $field->visible(fn (Get $get): bool => ($get('properties_page') ?: 'basic') === $definition['page']);
        }

        return $fields;
    }

    /** @param array<string, string> $parsed
     * @return array<string, mixed>
     */
    private function defaultsForKnownProperties(array $parsed): array
    {
        $defaults = [];
        foreach ($this->knownPropertyDefinitions() as $definition) {
            $key = $definition['key'];
            $formKey = $this->propertyFormKey($key);
            $value = $parsed[$key] ?? $definition['default'];
            $defaults[$formKey] = $definition['type'] === 'bool'
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : $value;
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeKnownPropertiesForSave(mixed $properties): array
    {
        if (! is_array($properties)) {
            return [];
        }

        $changes = [];
        foreach ($this->knownPropertyDefinitions() as $definition) {
            $key = $definition['key'];
            $formKey = $this->propertyFormKey($key);
            if (! array_key_exists($formKey, $properties)) {
                continue;
            }

            $value = $properties[$formKey];
            $changes[$key] = match ($definition['type']) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'int' => (int) $value,
                default => (string) $value,
            };
        }

        return $changes;
    }

    private function propertyFormKey(string $propertyKey): string
    {
        return str_replace(['.', '-'], ['__dot__', '__dash__'], $propertyKey);
    }

    /** @return array<int, array<string, mixed>> */
    private function knownPropertyDefinitions(): array
    {
        return [
            ['page' => 'basic', 'key' => 'motd', 'label' => 'motd', 'type' => 'text', 'default' => 'A Minecraft Server'],
            ['page' => 'basic', 'key' => 'level-name', 'label' => 'level-name', 'type' => 'text', 'default' => 'world'],
            ['page' => 'basic', 'key' => 'level-seed', 'label' => 'level-seed', 'type' => 'text', 'default' => ''],
            ['page' => 'basic', 'key' => 'level-type', 'label' => 'level-type', 'type' => 'select', 'default' => 'minecraft:normal', 'options' => ['minecraft:normal' => 'minecraft:normal', 'minecraft:flat' => 'minecraft:flat', 'minecraft:large_biomes' => 'minecraft:large_biomes']],
            ['page' => 'basic', 'key' => 'gamemode', 'label' => 'gamemode', 'type' => 'select', 'default' => 'survival', 'options' => ['survival' => 'survival', 'creative' => 'creative', 'adventure' => 'adventure', 'spectator' => 'spectator']],
            ['page' => 'basic', 'key' => 'difficulty', 'label' => 'difficulty', 'type' => 'select', 'default' => 'easy', 'options' => ['peaceful' => 'peaceful', 'easy' => 'easy', 'normal' => 'normal', 'hard' => 'hard']],
            ['page' => 'basic', 'key' => 'max-players', 'label' => 'max-players', 'type' => 'int', 'default' => 20],
            ['page' => 'basic', 'key' => 'hardcore', 'label' => 'hardcore', 'type' => 'bool', 'default' => false],
            ['page' => 'basic', 'key' => 'pvp', 'label' => 'pvp', 'type' => 'bool', 'default' => true],
            ['page' => 'basic', 'key' => 'allow-nether', 'label' => 'allow-nether', 'type' => 'bool', 'default' => true],
            ['page' => 'basic', 'key' => 'generate-structures', 'label' => 'generate-structures', 'type' => 'bool', 'default' => true],
            ['page' => 'basic', 'key' => 'spawn-monsters', 'label' => 'spawn-monsters', 'type' => 'bool', 'default' => true],
            ['page' => 'basic', 'key' => 'spawn-protection', 'label' => 'spawn-protection', 'type' => 'int', 'default' => 16],
            ['page' => 'access', 'key' => 'online-mode', 'label' => 'online-mode', 'type' => 'bool', 'default' => true],
            ['page' => 'access', 'key' => 'white-list', 'label' => 'white-list', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'enforce-whitelist', 'label' => 'enforce-whitelist', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'enforce-secure-profile', 'label' => 'enforce-secure-profile', 'type' => 'bool', 'default' => true],
            ['page' => 'access', 'key' => 'prevent-proxy-connections', 'label' => 'prevent-proxy-connections', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'hide-online-players', 'label' => 'hide-online-players', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'op-permission-level', 'label' => 'op-permission-level', 'type' => 'int', 'default' => 4],
            ['page' => 'access', 'key' => 'function-permission-level', 'label' => 'function-permission-level', 'type' => 'int', 'default' => 2],
            ['page' => 'access', 'key' => 'allow-flight', 'label' => 'allow-flight', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'enable-command-block', 'label' => 'enable-command-block', 'type' => 'bool', 'default' => false],
            ['page' => 'access', 'key' => 'force-gamemode', 'label' => 'force-gamemode', 'type' => 'bool', 'default' => false],
            ['page' => 'performance', 'key' => 'view-distance', 'label' => 'view-distance', 'type' => 'int', 'default' => 10],
            ['page' => 'performance', 'key' => 'simulation-distance', 'label' => 'simulation-distance', 'type' => 'int', 'default' => 10],
            ['page' => 'performance', 'key' => 'entity-broadcast-range-percentage', 'label' => 'entity-broadcast-range-percentage', 'type' => 'int', 'default' => 100],
            ['page' => 'performance', 'key' => 'max-tick-time', 'label' => 'max-tick-time', 'type' => 'int', 'default' => 60000],
            ['page' => 'performance', 'key' => 'max-world-size', 'label' => 'max-world-size', 'type' => 'int', 'default' => 29999984],
            ['page' => 'performance', 'key' => 'max-chained-neighbor-updates', 'label' => 'max-chained-neighbor-updates', 'type' => 'int', 'default' => 1000000],
            ['page' => 'performance', 'key' => 'network-compression-threshold', 'label' => 'network-compression-threshold', 'type' => 'int', 'default' => 256],
            ['page' => 'performance', 'key' => 'server-ip', 'label' => 'server-ip', 'type' => 'text', 'default' => ''],
            ['page' => 'performance', 'key' => 'server-port', 'label' => 'server-port', 'type' => 'int', 'default' => 25565],
            ['page' => 'performance', 'key' => 'enable-query', 'label' => 'enable-query', 'type' => 'bool', 'default' => false],
            ['page' => 'performance', 'key' => 'query.port', 'label' => 'query.port', 'type' => 'int', 'default' => 25565],
            ['page' => 'performance', 'key' => 'enable-rcon', 'label' => 'enable-rcon', 'type' => 'bool', 'default' => false],
            ['page' => 'performance', 'key' => 'rcon.port', 'label' => 'rcon.port', 'type' => 'int', 'default' => 25575],
            ['page' => 'performance', 'key' => 'rcon.password', 'label' => 'rcon.password', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'resource-pack', 'label' => 'resource-pack', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'resource-pack-id', 'label' => 'resource-pack-id', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'resource-pack-sha1', 'label' => 'resource-pack-sha1', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'resource-pack-prompt', 'label' => 'resource-pack-prompt', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'require-resource-pack', 'label' => 'require-resource-pack', 'type' => 'bool', 'default' => false],
            ['page' => 'resource', 'key' => 'initial-enabled-packs', 'label' => 'initial-enabled-packs', 'type' => 'text', 'default' => 'vanilla'],
            ['page' => 'resource', 'key' => 'initial-disabled-packs', 'label' => 'initial-disabled-packs', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'region-file-compression', 'label' => 'region-file-compression', 'type' => 'select', 'default' => 'deflate', 'options' => ['deflate' => 'deflate', 'lz4' => 'lz4', 'none' => 'none']],
            ['page' => 'resource', 'key' => 'sync-chunk-writes', 'label' => 'sync-chunk-writes', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'use-native-transport', 'label' => 'use-native-transport', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'enable-status', 'label' => 'enable-status', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'broadcast-console-to-ops', 'label' => 'broadcast-console-to-ops', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'broadcast-rcon-to-ops', 'label' => 'broadcast-rcon-to-ops', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'debug', 'label' => 'debug', 'type' => 'bool', 'default' => false],
            ['page' => 'resource', 'key' => 'log-ips', 'label' => 'log-ips', 'type' => 'bool', 'default' => true],
            ['page' => 'resource', 'key' => 'rate-limit', 'label' => 'rate-limit', 'type' => 'int', 'default' => 0],
            ['page' => 'resource', 'key' => 'player-idle-timeout', 'label' => 'player-idle-timeout', 'type' => 'int', 'default' => 0],
            ['page' => 'resource', 'key' => 'pause-when-empty-seconds', 'label' => 'pause-when-empty-seconds', 'type' => 'int', 'default' => 60],
            ['page' => 'resource', 'key' => 'bug-report-link', 'label' => 'bug-report-link', 'type' => 'text', 'default' => ''],
            ['page' => 'resource', 'key' => 'text-filtering-config', 'label' => 'text-filtering-config', 'type' => 'text', 'default' => ''],
        ];
    }

    public function save(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            app(MinecraftServerStateService::class)->assertOffline($server);
            $data = $this->form->getState();
            $setup = MinecraftToolkitSetup::query()
                ->where('server_uuid', $server->uuid)
                ->firstOrFail();
            $changes = $setup->software === 'bedrock'
                ? [
                    'server-name' => $data['motd'],
                    'max-players' => (int) $data['max_players'],
                    'view-distance' => (int) $data['view_distance'],
                    'tick-distance' => max(4, min(12, (int) $data['simulation_distance'])),
                    'online-mode' => (bool) $data['online_mode'],
                    'allow-list' => (bool) $data['whitelist'],
                ]
                : [
                    'motd' => $data['motd'],
                    'max-players' => (int) $data['max_players'],
                    'view-distance' => (int) $data['view_distance'],
                    'simulation-distance' => (int) $data['simulation_distance'],
                    'online-mode' => (bool) $data['online_mode'],
                    'white-list' => (bool) $data['whitelist'],
                    'pvp' => (bool) $data['pvp'],
                    'allow-flight' => (bool) $data['allow_flight'],
                ];
            $files = app(MinecraftServerFileService::class);
            $propertiesService = app(MinecraftPropertiesService::class);
            $rawProperties = is_string($data['server_properties_raw'] ?? null) ? (string) $data['server_properties_raw'] : '';
            $storedProperties = $files->read($server, '/server.properties', 1048576);
            if ($rawProperties !== '' && $rawProperties !== $storedProperties) {
                app(MinecraftRiskGateService::class)->assertAllowed('raw_properties', $server);
            }
            $current = $rawProperties !== '' ? $rawProperties : $storedProperties;
            $allChanges = array_merge($changes, $this->normalizeKnownPropertiesForSave($data['properties'] ?? []));
            $files->write($server, '/server.properties', $propertiesService->patch($current, $allChanges));
            $setup->fill(collect($data)->except('bedrock_allocation_id', 'server_properties_raw', 'properties', 'properties_page')->all())->save();

            Notification::make()->title(trans('minecrafttoolkit::strings.settings_page.saved'))->success()->send();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.settings_page.save_failed'))
                ->body($exception instanceof MinecraftToolkitException
                    ? $exception->getMessage()
                    : trans('minecrafttoolkit::strings.messages.wings_or_file_unreachable'))
                ->danger()
                ->send();
        }
    }

    public function installCrossplay(): void
    {
        try {
            $data = $this->form->getState();
            $configured = app(MinecraftCrossplayService::class)->install(
                $this->server(),
                $this->setup(),
                (int) ($data['bedrock_allocation_id'] ?? 0)
            );

            $notification = Notification::make()
                ->title(trans('minecrafttoolkit::strings.settings_page.crossplay_installed'))
                ->body($configured
                    ? trans('minecrafttoolkit::strings.settings_page.crossplay_configured_body')
                    : trans('minecrafttoolkit::strings.settings_page.crossplay_needs_first_start_body'))
                ->success();
            if (! $configured) {
                $notification->persistent();
            }
            $notification->send();
        } catch (MinecraftToolkitException $exception) {
            $this->crossplayError($exception);
        }
    }

    public function applyCrossplayConfig(): void
    {
        try {
            app(MinecraftCrossplayService::class)->applyConfig($this->server(), $this->setup());
            Notification::make()
                ->title(trans('minecrafttoolkit::strings.settings_page.crossplay_config_applied'))
                ->body(trans('minecrafttoolkit::strings.settings_page.crossplay_config_applied_body'))
                ->success()
                ->send();
        } catch (MinecraftToolkitException $exception) {
            $this->crossplayError($exception);
        }
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    private function setup(): MinecraftToolkitSetup
    {
        return MinecraftToolkitSetup::query()
            ->where('server_uuid', $this->server()->uuid)
            ->where('setup_status', 'completed')
            ->firstOrFail();
    }

    public function currentSetup(): MinecraftToolkitSetup
    {
        return $this->setup();
    }

    public function supportsCrossplay(): bool
    {
        return (bool) config('minecrafttoolkit.crossplay_enabled', true)
            && in_array($this->setup()->software, ['paper', 'purpur'], true);
    }

    /** @return array<int, string> */
    private function bedrockAllocationOptions(): array
    {
        $server = $this->server();

        $allocations = $server->allocations()
            ->where('id', '!=', $server->allocation_id)
            ->where('node_id', $server->node_id)
            ->get()
            ->mapWithKeys(fn ($allocation): array => [$allocation->id => $allocation->address])
            ->all();

        if (! (bool) config('minecrafttoolkit.bedrock_port_required', true) && $server->allocation) {
            return [$server->allocation->id => $server->allocation->address.' (geteilt)'] + $allocations;
        }

        return $allocations;
    }

    private function currentBedrockAllocationId(Server $server, MinecraftToolkitSetup $setup): ?int
    {
        if (! $setup->bedrock_allocation_port) {
            return null;
        }

        return $server->allocations()
            ->where('port', $setup->bedrock_allocation_port)
            ->where('ip', $setup->bedrock_allocation_ip)
            ->value('id');
    }

    private function crossplayError(MinecraftToolkitException $exception): void
    {
        Notification::make()
            ->title(trans('minecrafttoolkit::strings.settings_page.crossplay_failed'))
            ->body($this->localizedExceptionBody($exception))
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
