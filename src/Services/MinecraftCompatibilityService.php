<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class MinecraftCompatibilityService
{
    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly CurseForgeService $curseForge,
        private readonly GeyserDownloadService $geyser,
        private readonly MinecraftSoftwareService $software,
    ) {}

    /**
     * @return array{
     *   target: array<string, mixed>,
     *   packages: array<int, array<string, mixed>>,
     *   blocking: int,
     *   updates: int
     * }
     */
    public function check(
        Server $server,
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): array {
        $this->assertTargetAvailable($setup, $minecraftVersion, $loaderVersion);

        $target = $this->targetSetup($setup, $minecraftVersion, $loaderVersion);
        $packages = $this->compatiblePackages($server);
        $cacheKey = $this->cacheKey($server, $target, $packages);
        $ttl = max(0, (int) config('minecrafttoolkit.compatibility_cache_minutes', 30));
        if ($ttl <= 0) {
            return $this->buildReport($target, $packages, false);
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;

            return $cached;
        }

        $report = $this->buildReport($target, $packages, false);
        Cache::put($cacheKey, $report, now()->addMinutes($ttl));

        return $report;
    }

    private function assertTargetAvailable(
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): void {
        if (! array_key_exists($minecraftVersion, $this->software->versionOptions($setup->software))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.minecraft_version_unavailable'));
        }
        if (in_array($setup->software, ['fabric', 'forge', 'neoforge'], true)
            && (! is_string($loaderVersion)
                || ! array_key_exists(
                    $loaderVersion,
                    $this->software->loaderVersionOptions($setup->software, $minecraftVersion)
                ))) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.target_loader_version_invalid'));
        }
    }

    /** @return Collection<int, MinecraftToolkitPackage> */
    private function compatiblePackages(Server $server)
    {
        return MinecraftToolkitPackage::query()
            ->where('server_uuid', $server->uuid)
            ->where('enabled', true)
            ->whereIn('package_type', ['plugin', 'mod', 'dependency', 'crossplay'])
            ->orderBy('project_name')
            ->get();
    }

    /**
     * @param  Collection<int, MinecraftToolkitPackage>  $packageRecords
     * @return array{
     *   target: array<string, mixed>,
     *   packages: array<int, array<string, mixed>>,
     *   blocking: int,
     *   updates: int,
     *   cached: bool
     * }
     */
    private function buildReport(MinecraftToolkitSetup $target, $packageRecords, bool $cached): array
    {
        $packages = $packageRecords
            ->map(fn (MinecraftToolkitPackage $package): array => $this->checkPackage($package, $target))
            ->all();

        return [
            'target' => [
                'minecraft_version' => $target->minecraft_version,
                'loader' => $target->loader,
                'loader_version' => $target->loader_version,
            ],
            'packages' => $packages,
            'blocking' => collect($packages)->whereIn('status', ['incompatible', 'unknown', 'pinned'])->count(),
            'updates' => collect($packages)->whereIn('status', ['update_required', 'system_update'])->count(),
            'cached' => $cached,
        ];
    }

    /** @param Collection<int, MinecraftToolkitPackage> $packages */
    private function cacheKey(Server $server, MinecraftToolkitSetup $target, $packages): string
    {
        $fingerprint = $packages
            ->map(fn (MinecraftToolkitPackage $package): array => [
                'id' => $package->id,
                'source' => $package->source,
                'project' => $package->source_project_id,
                'version' => $package->source_version_id,
                'package_version' => $package->version_number,
                'pinned' => (bool) $package->update_pinned,
                'enabled' => (bool) $package->enabled,
                'updated_at' => optional($package->updated_at)->timestamp,
            ])
            ->values()
            ->all();

        return 'minecrafttoolkit.compatibility.'
            .$server->uuid.'.'
            .sha1(json_encode([
                'software' => $target->software,
                'minecraft_version' => $target->minecraft_version,
                'loader' => $target->loader,
                'loader_version' => $target->loader_version,
                'packages' => $fingerprint,
            ], JSON_THROW_ON_ERROR));
    }

    public function targetSetup(
        MinecraftToolkitSetup $setup,
        string $minecraftVersion,
        ?string $loaderVersion
    ): MinecraftToolkitSetup {
        $target = $setup->replicate();
        $target->forceFill([
            'minecraft_version' => $minecraftVersion,
            'loader_version' => in_array($setup->software, ['fabric', 'forge', 'neoforge'], true)
                ? $loaderVersion
                : null,
        ]);

        return $target;
    }

    /** @return array<string, mixed> */
    public function checkPackage(MinecraftToolkitPackage $package, MinecraftToolkitSetup $target): array
    {
        $base = [
            'id' => $package->id,
            'name' => $package->project_name,
            'source' => $package->source,
            'type' => $package->package_type,
            'current_version' => $package->version_number,
            'target_version' => null,
            'system' => $package->is_system_package,
            'pinned' => (bool) $package->update_pinned,
        ];

        if (in_array($package->source, ['modrinth', 'curseforge'], true)) {
            try {
                $candidate = $package->source === 'modrinth'
                    ? $this->modrinth->updateCandidate($package->source_project_id, $target)
                    : $this->curseForge->updateCandidate($package->source_project_id, $target);
                $version = $candidate['version'];
                $sameVersion = (string) $version['id'] === (string) $package->source_version_id;
                if (! $sameVersion && $package->update_pinned) {
                    return $base + [
                        'status' => 'pinned',
                        'target_version' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
                        'message' => trans('minecrafttoolkit::strings.messages.compat_pinned'),
                    ];
                }

                return $base + [
                    'status' => $sameVersion ? 'compatible' : 'update_required',
                    'target_version' => (string) ($version['version_number'] ?? $version['name'] ?? $version['id']),
                    'message' => $sameVersion
                        ? trans('minecrafttoolkit::strings.messages.compat_supported')
                        : trans('minecrafttoolkit::strings.messages.compat_update_available'),
                ];
            } catch (MinecraftToolkitException $exception) {
                $unknown = Str::contains($exception->getMessage(), ['nicht erreichbar', 'deaktiviert', 'unavailable', 'disabled']);

                return $base + [
                    'status' => $unknown ? 'unknown' : 'incompatible',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($package->source === 'geysermc'
            && in_array($package->source_project_id, ['geyser', 'floodgate'], true)) {
            try {
                $download = $this->geyser->latestSpigot($package->source_project_id);
                $sameBuild = (string) $download['build'] === (string) $package->source_version_id;

                return $base + [
                    'status' => $sameBuild
                        ? 'compatible'
                        : ($package->update_pinned ? 'pinned' : 'system_update'),
                    'target_version' => $download['version'].'+'.$download['build'],
                    'message' => trans('minecrafttoolkit::strings.messages.compat_crossplay_kept'),
                ];
            } catch (MinecraftToolkitException $exception) {
                return $base + [
                    'status' => 'unknown',
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $base + [
            'status' => 'unknown',
            'message' => trans('minecrafttoolkit::strings.messages.compat_no_data'),
        ];
    }
}
