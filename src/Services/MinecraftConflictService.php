<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Support\Collection;

class MinecraftConflictService
{
    private const PAIRS = [
        ['viarewind', 'protocolsupport'], ['geyser', 'geyser-spigot'], ['luckperms', 'luckperms-bukkit'],
        ['optifine', 'sodium'], ['starlight', 'phosphor'], ['paper', 'fabric-api'],
    ];

    /** @return array<int, array{severity: string, message: string}> */
    public function warnings(MinecraftToolkitSetup $setup, Collection $packages): array
    {
        $ids = $packages->flatMap(fn (MinecraftToolkitPackage $package): array => [$this->normalize($package->source_project_id), $this->normalize($package->source_project_slug), $this->normalize($package->project_name)])->filter()->unique();
        $warnings = [];
        foreach (self::PAIRS as [$first, $second]) {
            if ($ids->contains($first) && $ids->contains($second)) {
                $warnings[] = ['severity' => 'critical', 'message' => trans('minecrafttoolkit::strings.messages.conflict_known', ['first' => $first, 'second' => $second])];
            }
        }
        foreach ($packages as $package) {
            if ($package->package_type === 'mod' && $package->loader && ! in_array($package->loader, [$setup->loader, $setup->software], true)) {
                $warnings[] = ['severity' => 'warning', 'message' => trans('minecrafttoolkit::strings.messages.conflict_wrong_loader', ['package' => $package->project_name, 'loader' => $package->loader, 'software' => $setup->software])];
            }
            if ($setup->software === 'folia' && $package->package_type === 'plugin' && ! str_contains(strtolower((string) $package->side), 'folia')) {
                $warnings[] = ['severity' => 'warning', 'message' => trans('minecrafttoolkit::strings.messages.conflict_no_folia', ['package' => $package->project_name])];
            }
        }

        return array_values(array_unique($warnings, SORT_REGULAR));
    }

    private function normalize(?string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value)) ?? '';
    }
}
