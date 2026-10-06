<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Console\Commands;

use Illuminate\Console\Command;

class CheckMinecraftToolkitTranslationsCommand extends Command
{
    protected $signature = 'minecraft-toolkit:translations';

    protected $description = 'Checks Minecraft Toolkit translation keys and character encoding.';

    public function handle(): int
    {
        $base = plugin_path('minecrafttoolkit', 'lang');
        $english = $this->flatten(require $base.'/en/strings.php');
        $german = $this->flatten(require $base.'/de/strings.php');
        $checks = [
            'Missing in German' => array_diff_key($english, $german),
            'Missing in English' => array_diff_key($german, $english),
            'Encoding errors' => array_filter($english + $german, fn (mixed $value): bool => is_string($value) && preg_match('/Ã.|Â.|â€/', $value) === 1),
        ];
        foreach ($checks as $label => $items) {
            $this->line($label.': '.($items === [] ? 'none' : implode(', ', array_keys($items))));
        }

        return collect($checks)->every(fn (array $items): bool => $items === []) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = ltrim($prefix.'.'.$key, '.');
            $flat += is_array($value) ? $this->flatten($value, $path) : [$path => $value];
        }

        return $flat;
    }
}
