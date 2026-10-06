<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Filament\Server\Pages;

use App\Models\Server;
use BackedEnum;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitProfile;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use BlueWolf\MinecraftToolkit\Services\MinecraftPermissionService;
use BlueWolf\MinecraftToolkit\Services\MinecraftProfileService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class MinecraftProfilesPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'tabler-template';

    protected static UnitEnum|string|null $navigationGroup = 'Minecraft Toolkit';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'minecraft-profiles';

    protected string $view = 'minecrafttoolkit::filament.server.pages.minecraft-profiles';

    public array $profiles = [];

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->refreshProfiles();
    }

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return Schema::hasTable('minecraft_toolkit_profiles') && $server instanceof Server && user() !== null
            && app(MinecraftPermissionService::class)->canModify(user(), $server) && MinecraftToolkitSetup::query()->where('server_uuid', $server->uuid)->where('setup_status', 'completed')->exists();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecrafttoolkit::strings.profiles.title');
    }

    public function getTitle(): string
    {
        return trans('minecrafttoolkit::strings.profiles.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('capture')->label(trans('minecrafttoolkit::strings.profiles.capture'))->schema([
                TextInput::make('name')->required()->maxLength(255), Textarea::make('description')->maxLength(2000), Toggle::make('shared')->default(false),
            ])->action(function (array $data): void {
                try {
                    app(MinecraftProfileService::class)->capture($this->server(), $this->setup(), (string) $data['name'], (string) ($data['description'] ?? ''), (bool) ($data['shared'] ?? false));
                    $this->refreshProfiles();
                    Notification::make()->success()->title(trans('minecrafttoolkit::strings.profiles.saved'))->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title(trans('minecrafttoolkit::strings.profiles.failed'))->body(trans('minecrafttoolkit::strings.common.action_failed_body'))->send();
                }
            }),
            Action::make('import')->label(trans('minecrafttoolkit::strings.profiles.import'))->schema([
                FileUpload::make('file')->acceptedFileTypes(['application/json'])->maxSize(2048)->storeFiles(false)->required(),
            ])->action(function (array $data): void {
                try {
                    app(MinecraftProfileService::class)->import($this->uploadContents($data['file']));
                    $this->refreshProfiles();
                    Notification::make()->success()->title(trans('minecrafttoolkit::strings.profiles.imported'))->send();
                } catch (\Throwable $exception) {
                    report($exception);
                    Notification::make()->danger()->title(trans('minecrafttoolkit::strings.profiles.failed'))->body(trans('minecrafttoolkit::strings.common.action_failed_body'))->send();
                }
            }),
        ];
    }

    public function applyProfile(int $id): void
    {
        try {
            $profile = $this->profile($id);
            $result = app(MinecraftProfileService::class)->apply($this->server(), $this->setup(), $profile);
            Notification::make()->success()->title(trans('minecrafttoolkit::strings.profiles.applied'))->body(trans('minecrafttoolkit::strings.profiles.applied_body', $result))->send();
        } catch (MinecraftToolkitException $exception) {
            Notification::make()->danger()->title(trans('minecrafttoolkit::strings.profiles.failed'))->body($exception->getMessage())->send();
        }
    }

    public function exportProfile(int $id): StreamedResponse
    {
        $profile = $this->profile($id);
        $json = app(MinecraftProfileService::class)->export($profile);

        return response()->streamDownload(fn () => print ($json), str($profile->name)->slug().'.minecraft-toolkit.json', ['Content-Type' => 'application/json']);
    }

    public function deleteProfile(int $id): void
    {
        $this->profile($id)->delete();
        $this->refreshProfiles();
    }

    private function refreshProfiles(): void
    {
        $this->profiles = MinecraftToolkitProfile::query()->where(fn ($query) => $query->where('user_id', user()?->id)->orWhere('shared', true))->latest()->get()->map(fn ($profile): array => [
            'id' => $profile->id, 'name' => $profile->name, 'description' => $profile->description, 'software' => $profile->software_json['software'] ?? '-', 'packages' => count($profile->packages_json ?? []), 'owned' => $profile->user_id === user()?->id,
        ])->all();
    }

    private function profile(int $id): MinecraftToolkitProfile
    {
        return MinecraftToolkitProfile::query()->whereKey($id)->where(fn ($query) => $query->where('user_id', user()?->id)->orWhere('shared', true))->firstOrFail();
    }

    private function uploadContents(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if ($value instanceof TemporaryUploadedFile) {
            return (string) file_get_contents($value->getRealPath());
        }
        $path = is_string($value) ? storage_path('app/'.ltrim($value, '/')) : '';
        if (! is_file($path)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.profile_file_not_found'));
        }

        return (string) file_get_contents($path);
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
