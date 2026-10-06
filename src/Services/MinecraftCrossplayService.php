<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use App\Models\Allocation;
use App\Models\Server;
use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitLog;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitPackage;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Support\Facades\Log;

class MinecraftCrossplayService
{
    public const CONFIG_PATH = '/plugins/Geyser-Spigot/config.yml';

    public function __construct(
        private readonly GeyserDownloadService $downloads,
        private readonly MinecraftServerFileService $files,
        private readonly MinecraftServerStateService $state,
        private readonly MinecraftRiskGateService $riskGate,
    ) {}

    public function install(Server $server, MinecraftToolkitSetup $setup, int $allocationId): bool
    {
        $this->riskGate->assertAllowed('crossplay_setup', $server);
        $this->assertSupported($setup);
        $this->state->assertOffline($server);
        $allocation = $this->resolveAllocation($server, $allocationId);

        foreach (['geyser', 'floodgate'] as $project) {
            $download = $this->downloads->latestSpigot($project);
            $path = '/plugins/'.$download['file_name'];
            $this->files->backupIfPresent($server, $path);
            $this->files->downloadJar($server, $download['url'], $path, ['sha256' => $download['sha256']]);

            MinecraftToolkitPackage::query()->updateOrCreate(
                [
                    'server_uuid' => $server->uuid,
                    'source' => 'geysermc',
                    'source_project_id' => $project,
                ],
                [
                    'setup_id' => $setup->id,
                    'source_project_slug' => $project,
                    'source_version_id' => $download['build'],
                    'project_name' => $project === 'geyser' ? 'Geyser' : 'Floodgate',
                    'project_type' => 'plugin',
                    'package_type' => 'crossplay',
                    'loader' => $setup->software,
                    'minecraft_version' => $setup->minecraft_version,
                    'version_number' => $download['version'].'+'.$download['build'],
                    'file_name' => $download['file_name'],
                    'file_path' => $path,
                    'download_url' => $download['url'],
                    'sha512' => null,
                    'side' => 'server',
                    'dependencies_json' => ['sha256' => $download['sha256']],
                    'is_required_dependency' => false,
                    'is_system_package' => true,
                    'managed' => true,
                    'enabled' => true,
                    'installed_by' => user()?->id,
                    'installed_at' => now(),
                ]
            );
        }

        $setup->forceFill([
            'bedrock_allocation_ip' => $allocation->ip,
            'bedrock_allocation_port' => $allocation->port,
            'crossplay_enabled' => true,
            'geyser_enabled' => true,
            'floodgate_enabled' => true,
        ])->save();

        $configured = $this->applyConfigIfPresent($server, $setup->refresh());
        $this->log($server, 'crossplay_installed', 'success', trans('minecrafttoolkit::strings.messages.crossplay_installed'), [
            'bedrock_port' => $allocation->port,
            'config_applied' => $configured,
        ]);

        return $configured;
    }

    public function applyConfig(Server $server, MinecraftToolkitSetup $setup): void
    {
        $this->riskGate->assertAllowed('crossplay_setup', $server);
        $this->assertSupported($setup);
        $this->state->assertOffline($server);
        if (! $this->applyConfigIfPresent($server, $setup)) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.geyser_config_missing')
            );
        }

        $this->log($server, 'crossplay_configured', 'success', trans('minecrafttoolkit::strings.messages.crossplay_configured'));
    }

    public function patchConfig(string $yaml, int $bedrockPort, ?string $motd = null): string
    {
        $motd = trim((string) $motd);
        $yaml = $this->patchYamlSectionValue($yaml, 'bedrock', 'port', (string) $bedrockPort);
        $yaml = $this->patchYamlSectionValue($yaml, 'bedrock', 'address', '0.0.0.0');
        $yaml = $this->patchYamlSectionValue($yaml, 'bedrock', 'clone-remote-port', 'false');
        if ($motd !== '') {
            $yaml = $this->patchYamlSectionValue($yaml, 'motd', 'primary-motd', $this->quoteYamlValue($motd));
            $yaml = $this->patchYamlSectionValue($yaml, 'motd', 'secondary-motd', $this->quoteYamlValue('Minecraft Toolkit'));
            $yaml = $this->patchYamlSectionValue($yaml, 'motd', 'passthrough-motd', 'false');
        }

        $yaml = $this->patchYamlSectionValue($yaml, 'java', 'auth-type', 'floodgate');
        $yaml = $this->patchYamlSectionValue($yaml, 'remote', 'auth-type', 'floodgate');
        $yaml = $this->forceAuthTypeFloodgate($yaml);

        return rtrim($yaml)."\n";
    }

    public function resolveAllocation(Server $server, int $allocationId): Allocation
    {
        $allocation = $server->allocations()
            ->whereKey($allocationId)
            ->where('node_id', $server->node_id)
            ->first();

        if (! $allocation instanceof Allocation) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.bedrock_allocation_required')
            );
        }
        if ($allocation->id === $server->allocation_id
            && (bool) config('minecrafttoolkit.bedrock_port_required', true)) {
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.bedrock_allocation_required')
            );
        }

        return $allocation;
    }

    private function applyConfigIfPresent(Server $server, MinecraftToolkitSetup $setup): bool
    {
        if (! $setup->bedrock_allocation_port || ! $this->files->exists($server, self::CONFIG_PATH)) {
            return false;
        }

        $contents = $this->files->read($server, self::CONFIG_PATH, 2097152);
        $this->files->backupIfPresent($server, self::CONFIG_PATH);
        $this->files->write(
            $server,
            self::CONFIG_PATH,
            $this->patchConfig($contents, (int) $setup->bedrock_allocation_port, (string) $setup->motd)
        );

        return true;
    }

    private function forceAuthTypeFloodgate(string $yaml): string
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $found = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)auth-type\s*:\s*.*$/i', $line, $match)) {
                $lines[$index] = ($match[1] ?? '').'auth-type: floodgate';
                $found = true;
            }
        }

        if (! $found) {
            $yaml = $this->patchYamlSectionValue(implode("\n", $lines), 'remote', 'auth-type', 'floodgate');

            return $yaml;
        }

        return implode("\n", $lines);
    }

    private function quoteYamlValue(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ' '], $value).'"';
    }

    private function patchYamlSectionValue(string $yaml, string $section, string $key, string $value): string
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $sectionIndex = null;
        $sectionIndent = 0;

        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)'.preg_quote($section, '/').':\s*(?:#.*)?$/', $line, $match)) {
                $sectionIndex = $index;
                $sectionIndent = strlen($match[1]);
                break;
            }
        }

        if ($sectionIndex === null) {
            $lines[] = "$section:";
            $lines[] = "  $key: $value";

            return implode("\n", $lines);
        }

        $insertAt = count($lines);
        for ($index = $sectionIndex + 1; $index < count($lines); $index++) {
            $line = $lines[$index];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            preg_match('/^(\s*)/', $line, $indentMatch);
            $indent = strlen($indentMatch[1] ?? '');
            if ($indent <= $sectionIndent) {
                $insertAt = $index;
                break;
            }

            if (preg_match('/^\s+'.preg_quote($key, '/').':\s*.*$/', $line)) {
                $lines[$index] = str_repeat(' ', $sectionIndent + 2)."$key: $value";

                return implode("\n", $lines);
            }
        }

        array_splice($lines, $insertAt, 0, [
            str_repeat(' ', $sectionIndent + 2)."$key: $value",
        ]);

        return implode("\n", $lines);
    }

    private function assertSupported(MinecraftToolkitSetup $setup): void
    {
        if (! (bool) config('minecrafttoolkit.crossplay_enabled', true)
            || ! in_array($setup->software, ['paper', 'purpur'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.crossplay_unsupported'));
        }
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
            Log::warning('Minecraft Toolkit could not persist a crossplay log.', ['exception' => $exception]);
        }
    }
}
