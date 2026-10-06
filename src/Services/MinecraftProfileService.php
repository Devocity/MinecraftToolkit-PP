<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitProfile;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;

class MinecraftProfileService
{
    public function __construct(private readonly MinecraftPackageInstaller $installer, private readonly MinecraftServerStateService $state, private readonly MinecraftServerFileService $files, private readonly MinecraftPropertiesService $properties) {}

    public function capture(Server $server, MinecraftToolkitSetup $setup, string $name, string $description = '', bool $shared = false): MinecraftToolkitProfile
    {
        $packages = MinecraftToolkitPackage::query()->where('server_uuid', $server->uuid)->where('managed', true)->get()->map(fn ($package): array => [
            'source' => $package->source, 'project_id' => $package->source_project_id, 'name' => $package->project_name, 'pinned' => (bool) $package->update_pinned,
        ])->all();

        return MinecraftToolkitProfile::query()->create([
            'user_id' => user()?->id, 'name' => substr(trim($name), 0, 255), 'description' => trim($description),
            'software_json' => ['software' => $setup->software, 'edition' => $setup->edition, 'minecraft_version' => $setup->minecraft_version, 'loader' => $setup->loader, 'loader_version' => $setup->loader_version],
            'packages_json' => $packages,
            'setup_json' => $setup->only(['motd', 'level_name', 'max_players', 'gamemode', 'difficulty', 'online_mode', 'whitelist', 'pvp', 'allow_nether', 'spawn_protection', 'view_distance', 'simulation_distance', 'allow_flight']),
            'shared' => $shared,
        ]);
    }

    public function apply(Server $server, MinecraftToolkitSetup $setup, MinecraftToolkitProfile $profile): array
    {
        $software = (array) $profile->software_json;
        if (($software['software'] ?? null) !== $setup->software) {
            throw new MinecraftToolkitException('Das Profil verwendet eine andere Serversoftware. Nutze zuerst den sicheren Softwarewechsel.');
        }
        $this->state->assertOffline($server);
        $installed = 0;
        $skipped = 0;
        foreach ((array) $profile->packages_json as $package) {
            if (! is_array($package) || ! is_string($package['project_id'] ?? null)) {
                continue;
            }
            if (MinecraftToolkitPackage::query()->where('server_uuid', $server->uuid)->where('source', $package['source'] ?? '')->where('source_project_id', $package['project_id'])->where('managed', true)->exists()) {
                $skipped++;

                continue;
            }
            $record = match ($package['source'] ?? '') {
                'modrinth' => $this->installer->installModrinthPackage($server, $setup, $package['project_id']),
                'curseforge' => $this->installer->installCurseForgePackage($server, $setup, $package['project_id']),
                default => null,
            };
            if ($record) {
                $record->update(['update_pinned' => (bool) ($package['pinned'] ?? false)]);
                $installed++;
            }
        }
        $settings = (array) $profile->setup_json;
        if ($settings !== []) {
            $raw = $this->files->read($server, '/server.properties', 1048576);
            $changes = [
                'motd' => $settings['motd'] ?? $setup->motd, 'level-name' => $settings['level_name'] ?? $setup->level_name,
                'max-players' => $settings['max_players'] ?? $setup->max_players, 'gamemode' => $settings['gamemode'] ?? $setup->gamemode,
                'difficulty' => $settings['difficulty'] ?? $setup->difficulty, 'online-mode' => $settings['online_mode'] ?? $setup->online_mode,
                'white-list' => $settings['whitelist'] ?? $setup->whitelist, 'pvp' => $settings['pvp'] ?? $setup->pvp,
                'view-distance' => $settings['view_distance'] ?? $setup->view_distance, 'simulation-distance' => $settings['simulation_distance'] ?? $setup->simulation_distance,
            ];
            $this->files->writeAtomically($server, '/server.properties', $this->properties->patch($raw, $changes));
            $setup->update(array_intersect_key($settings, array_flip(['motd', 'level_name', 'max_players', 'gamemode', 'difficulty', 'online_mode', 'whitelist', 'pvp', 'allow_nether', 'spawn_protection', 'view_distance', 'simulation_distance', 'allow_flight'])));
        }

        return compact('installed', 'skipped');
    }

    public function export(MinecraftToolkitProfile $profile): string
    {
        return json_encode(['format' => 'minecraft-toolkit-profile-v1', 'profile' => $profile->only(['name', 'description', 'software_json', 'packages_json', 'setup_json'])], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function import(string $json): MinecraftToolkitProfile
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $data = $payload['profile'] ?? null;
        if (($payload['format'] ?? null) !== 'minecraft-toolkit-profile-v1' || ! is_array($data) || ! is_array($data['software_json'] ?? null) || ! is_array($data['packages_json'] ?? null)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.profile_file_invalid'));
        }

        return MinecraftToolkitProfile::query()->create([
            'user_id' => user()?->id, 'name' => substr((string) ($data['name'] ?? 'Importiertes Profil'), 0, 255), 'description' => (string) ($data['description'] ?? ''),
            'software_json' => $data['software_json'], 'packages_json' => array_slice($data['packages_json'], 0, 500), 'setup_json' => is_array($data['setup_json'] ?? null) ? $data['setup_json'] : [], 'shared' => false,
        ]);
    }
}
