<?php

declare(strict_types=1);

namespace BlueWolf\MinecraftToolkit\Services;

use BlueWolf\MinecraftToolkit\Exceptions\MinecraftToolkitException;
use BlueWolf\MinecraftToolkit\Models\MinecraftToolkitSetup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ModrinthService
{
    private const API = 'https://api.modrinth.com/v2';

    /** @return array<int, array<string, mixed>> */
    public function searchPackages(string $query, MinecraftToolkitSetup $setup, int $limit = 20): array
    {
        $this->assertEnabled();
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.search_too_short'));
        }

        $facets = $this->searchFacets($setup);
        $key = 'minecrafttoolkit.modrinth.search.'.sha1(json_encode([$query, $facets, $limit], JSON_THROW_ON_ERROR));
        $data = Cache::remember($key, now()->addMinutes(10), fn (): array => $this->get('/search', [
            'query' => $query,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => 'relevance',
            'limit' => min(max($limit, 1), 100),
        ]));

        return $this->normalizeSearchResults($data['hits'] ?? []);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchPlugins(string $query, MinecraftToolkitSetup $setup, int $limit = 20): array
    {
        return $this->searchPackages($query, $setup, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function popularPackages(MinecraftToolkitSetup $setup, int $offset = 0, int $limit = 20): array
    {
        $this->assertEnabled();

        if (! in_array($setup->software, ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'], true)) {
            return [];
        }

        $facets = $this->searchFacets($setup);
        $offset = max(0, $offset);
        $limit = min(max($limit, 1), 100);
        $key = 'minecrafttoolkit.modrinth.popular.'.sha1(json_encode([$facets, $offset, $limit], JSON_THROW_ON_ERROR));
        $data = Cache::remember($key, now()->addMinutes(10), fn (): array => $this->get('/search', [
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
            'index' => 'downloads',
            'offset' => $offset,
            'limit' => $limit,
        ]));

        return $this->normalizeSearchResults($data['hits'] ?? []);
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function installationCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        $this->assertEnabled();
        $this->assertIdentifier($projectId);
        if (! in_array($setup->software, ['paper', 'purpur', 'folia', 'fabric', 'forge', 'neoforge'], true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.software_no_modrinth'));
        }

        $project = $this->project($projectId);
        if (($project['project_type'] ?? null) !== 'mod'
            || ($project['server_side'] ?? 'unknown') === 'unsupported') {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.project_not_server_side'));
        }

        $versions = $this->versions($projectId, $setup);
        usort($versions, fn (array $a, array $b): int => strcmp(
            (string) ($b['date_published'] ?? ''),
            (string) ($a['date_published'] ?? '')
        ));
        $version = collect($versions)->firstWhere('version_type', 'release') ?? ($versions[0] ?? null);
        if (! is_array($version)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.no_compatible_version'));
        }

        $file = collect($version['files'] ?? [])->firstWhere('primary', true)
            ?? collect($version['files'] ?? [])->first();
        if (! is_array($file)
            || ! is_string($file['url'] ?? null)
            || ! is_string($file['filename'] ?? null)
            || strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION)) !== 'jar') {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.version_no_jar'));
        }

        $version['selected_file'] = $file;
        $normalizedProject = $this->normalizeProject($project);
        $dependencies = $this->dependencyDetails($version);
        $dependencies = $this->withKnownPluginDependencies($normalizedProject, $dependencies);

        return [
            'project' => $normalizedProject,
            'version' => $version,
            'dependencies' => $dependencies,
            'warning' => $setup->software === 'folia'
                ? trans('minecrafttoolkit::strings.messages.folia_compat_warning')
                : null,
        ];
    }

    /** @return array{project: array<string, mixed>, version: array<string, mixed>, dependencies: array<int, array<string, mixed>>, warning: ?string} */
    public function updateCandidate(string $projectId, MinecraftToolkitSetup $setup): array
    {
        return $this->installationCandidate($projectId, $setup);
    }

    /** @param array<int, mixed> $hits
     * @return array<int, array<string, mixed>>
     */
    public function normalizeSearchResults(array $hits): array
    {
        return collect($hits)
            ->filter(fn (mixed $hit): bool => is_array($hit) && is_string($hit['project_id'] ?? null))
            ->map(fn (array $hit): array => [
                'project_id' => $hit['project_id'],
                'slug' => (string) ($hit['slug'] ?? $hit['project_id']),
                'title' => (string) ($hit['title'] ?? 'Unbekanntes Projekt'),
                'description' => (string) ($hit['description'] ?? ''),
                'icon_url' => is_string($hit['icon_url'] ?? null) ? $hit['icon_url'] : null,
                'project_url' => 'https://modrinth.com/'.(in_array((string) ($hit['project_type'] ?? ''), ['plugin', 'mod'], true)
                    ? (string) ($hit['project_type'] ?? 'mod')
                    : 'mod').'/'.(string) ($hit['slug'] ?? $hit['project_id']),
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'author' => (string) ($hit['author'] ?? ''),
                'server_side' => (string) ($hit['server_side'] ?? 'unknown'),
                'categories' => array_values(array_filter($hit['display_categories'] ?? $hit['categories'] ?? [], 'is_string')),
                'updated_at' => is_string($hit['date_modified'] ?? null) ? $hit['date_modified'] : null,
                'versions' => array_values(array_filter($hit['versions'] ?? [], 'is_string')),
            ])
            ->values()
            ->all();
    }

    /** @return string[] */
    public function loaderCandidates(string $software): array
    {
        return match ($software) {
            'paper' => ['paper', 'spigot', 'bukkit'],
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'folia' => ['folia', 'paper'],
            'fabric' => ['fabric'],
            'forge' => ['forge'],
            'neoforge' => ['neoforge'],
            default => [],
        };
    }

    /** @return array<int, array<int, string>> */
    public function searchFacets(MinecraftToolkitSetup $setup): array
    {
        return [
            array_map(
                fn (string $loader): string => "categories:$loader",
                $this->loaderCandidates($setup->software)
            ),
            ["versions:{$setup->minecraft_version}"],
            [in_array($setup->software, ['paper', 'purpur', 'folia'], true)
                ? 'project_type:plugin'
                : 'project_type:mod'],
            ['server_side:required', 'server_side:optional'],
        ];
    }

    /** @return array<string, mixed> */
    private function project(string $projectId): array
    {
        return Cache::remember(
            "minecrafttoolkit.modrinth.project.$projectId",
            now()->addHour(),
            fn (): array => $this->get("/project/$projectId")
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function versions(string $projectId, MinecraftToolkitSetup $setup): array
    {
        $loaders = $this->loaderCandidates($setup->software);
        $key = 'minecrafttoolkit.modrinth.versions.'.sha1(json_encode([
            $projectId,
            $loaders,
            $setup->minecraft_version,
        ], JSON_THROW_ON_ERROR));

        return Cache::remember($key, now()->addMinutes(10), fn (): array => $this->get(
            "/project/$projectId/version",
            [
                'loaders' => json_encode($loaders, JSON_THROW_ON_ERROR),
                'game_versions' => json_encode([$setup->minecraft_version], JSON_THROW_ON_ERROR),
                'include_changelog' => 'false',
            ]
        ));
    }

    /** @param array<string, mixed> $version
     * @return array<int, array<string, mixed>>
     */
    private function dependencyDetails(array $version): array
    {
        return collect($version['dependencies'] ?? [])
            ->filter(fn (mixed $dependency): bool => is_array($dependency)
                && in_array($dependency['dependency_type'] ?? null, ['required', 'optional'], true))
            ->take(30)
            ->map(function (array $dependency): array {
                $projectId = $dependency['project_id'] ?? null;
                $project = [];

                try {
                    if (! is_string($projectId) && is_string($dependency['version_id'] ?? null)) {
                        $dependencyVersion = $this->getVersion($dependency['version_id']);
                        $projectId = $dependencyVersion['project_id'] ?? null;
                    }

                    $project = is_string($projectId) ? $this->project($projectId) : [];
                } catch (\Throwable $exception) {
                    report($exception);
                }

                return [
                    'project_id' => $projectId,
                    'version_id' => is_string($dependency['version_id'] ?? null) ? $dependency['version_id'] : null,
                    'type' => (string) $dependency['dependency_type'],
                    'title' => (string) ($project['title'] ?? $dependency['file_name'] ?? $projectId ?? 'Unbekannte Dependency'),
                    'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $project
     * @param  array<int, array<string, mixed>>  $dependencies
     * @return array<int, array<string, mixed>>
     */
    private function withKnownPluginDependencies(array $project, array $dependencies): array
    {
        $slug = strtolower((string) ($project['slug'] ?? ''));
        $title = strtolower((string) ($project['title'] ?? ''));
        $known = [];

        if ($slug === 'viarewind' || str_contains($title, 'viarewind')) {
            $known[] = [
                'project_id' => 'viabackwards',
                'version_id' => null,
                'type' => 'required',
                'title' => 'ViaBackwards',
                'slug' => 'viabackwards',
            ];
        }

        foreach ($known as $dependency) {
            $exists = collect($dependencies)->contains(fn (array $existing): bool => ($existing['project_id'] ?? null) === $dependency['project_id']
                || ($existing['slug'] ?? null) === $dependency['slug']
            );
            if (! $exists) {
                $dependencies[] = $dependency;
            }
        }

        return $dependencies;
    }

    /** @return array<string, mixed> */
    private function getVersion(string $versionId): array
    {
        $this->assertIdentifier($versionId);

        return Cache::remember(
            "minecrafttoolkit.modrinth.version.$versionId",
            now()->addMinutes(10),
            fn (): array => $this->get("/version/$versionId")
        );
    }

    /** @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function normalizeProject(array $project): array
    {
        return [
            'project_id' => (string) ($project['id'] ?? ''),
            'slug' => (string) ($project['slug'] ?? $project['id'] ?? ''),
            'title' => (string) ($project['title'] ?? 'Unbekanntes Projekt'),
            'description' => (string) ($project['description'] ?? ''),
            'icon_url' => is_string($project['icon_url'] ?? null) ? $project['icon_url'] : null,
            'project_url' => is_string($project['slug'] ?? null)
                ? 'https://modrinth.com/'.(string) ($project['project_type'] ?? 'mod').'/'.$project['slug']
                : null,
            'downloads' => (int) ($project['downloads'] ?? 0),
            'server_side' => (string) ($project['server_side'] ?? 'unknown'),
            'categories' => array_values(array_filter($project['categories'] ?? [], 'is_string')),
            'published_at' => is_string($project['published'] ?? null) ? $project['published'] : null,
            'updated_at' => is_string($project['updated'] ?? null) ? $project['updated'] : null,
        ];
    }

    /** @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            return Http::acceptJson()
                ->withUserAgent((string) config('minecrafttoolkit.user_agent'))
                ->connectTimeout(5)
                ->timeout((int) config('minecrafttoolkit.http_timeout', 20))
                ->get(self::API.$path, $query)
                ->throw()
                ->json();
        } catch (\Throwable $exception) {
            report($exception);
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_unavailable'), previous: $exception);
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('minecrafttoolkit.modrinth_enabled', true)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_disabled'));
        }
    }

    private function assertIdentifier(string $identifier): void
    {
        if (! preg_match('/^[A-Za-z0-9!@$()`.+,\'_-]{3,64}$/', $identifier)) {
            throw new MinecraftToolkitException(trans('minecrafttoolkit::strings.messages.modrinth_project_id_invalid'));
        }
    }
}
