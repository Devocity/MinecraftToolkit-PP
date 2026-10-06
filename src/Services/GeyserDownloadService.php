<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeyserDownloadService
{
    private const API = 'https://download.geysermc.org/v2';

    /** @return array{project: string, version: string, build: string, file_name: string, url: string, sha256: string} */
    public function latestSpigot(string $project): array
    {
        if (! in_array($project, ['geyser', 'floodgate'], true)) {
            throw new MinecraftToolkitException('Unbekanntes GeyserMC-Projekt.');
        }

        return Cache::remember(
            "minecrafttoolkit.geysermc.$project.spigot",
            now()->addMinutes(30),
            function () use ($project): array {
                $metadata = $this->get("/projects/$project");
                $version = collect($metadata['versions'] ?? [])->last();
                if (! is_string($version)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.geyser_no_version', ['project' => $project]));
                }

                $build = $this->get("/projects/$project/versions/$version/builds/latest");
                $buildNumber = $build['build'] ?? null;
                $download = $build['downloads']['spigot'] ?? null;
                if ((! is_int($buildNumber) && ! is_string($buildNumber))
                    || ! is_array($download)
                    || ! is_string($download['name'] ?? null)
                    || ! is_string($download['sha256'] ?? null)) {
                    throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.geyser_no_spigot_download', ['project' => $project]));
                }

                return [
                    'project' => $project,
                    'version' => $version,
                    'build' => (string) $buildNumber,
                    'file_name' => $download['name'],
                    'url' => self::API."/projects/$project/versions/$version/builds/$buildNumber/downloads/spigot",
                    'sha256' => $download['sha256'],
                ];
            }
        );
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        try {
            return Http::acceptJson()
                ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
                ->connectTimeout(5)
                ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
                ->get(self::API.$path)
                ->throw()
                ->json();
        } catch (MinecraftToolkitException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new MinecraftToolkitException(
                trans('minecrafttoolkit::strings.messages.geyser_unavailable'),
                previous: $exception
            );
        }
    }
}
