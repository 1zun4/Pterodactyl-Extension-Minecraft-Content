<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;

final class Modrinth implements Source
{
    private const string API = 'https://api.modrinth.com/v2';

    private const array SORTS = ['relevance' => 'relevance', 'downloads' => 'downloads', 'updated' => 'updated', 'newest' => 'newest'];

    public function name(): string
    {
        return 'modrinth';
    }

    public function supports(ContentType $type, Platform $platform): bool
    {
        return $platform->supports($type);
    }

    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array
    {
        $facets = [['project_type:'.$type->value]];
        $loaders = $this->loaders($type, $platform);

        if ($loaders !== [] && in_array($type, [ContentType::Plugin, ContentType::Mod], true)) {
            $facets[] = array_map(static fn (string $loader): string => "categories:{$loader}", $loaders);
        }

        if ($platform->minecraft !== null && $type !== ContentType::Modpack) {
            $facets[] = ["versions:{$platform->minecraft}"];
        }

        if (in_array($type, [ContentType::Mod, ContentType::Modpack], true)) {
            $facets[] = ['server_side:required', 'server_side:optional'];
        }

        $result = Api::get(self::API.'/search', [
            'query' => $query,
            'facets' => json_encode($facets),
            'index' => self::SORTS[$sort] ?? 'relevance',
            'limit' => 20,
            'offset' => max(0, $page - 1) * 20,
        ], ttl: 300);

        return [
            'total' => (int) ($result['total_hits'] ?? 0),
            'projects' => array_map(fn (array $hit): array => [
                'source' => $this->name(),
                'id' => (string) $hit['project_id'],
                'slug' => (string) ($hit['slug'] ?? $hit['project_id']),
                'name' => (string) ($hit['title'] ?? ''),
                'author' => (string) ($hit['author'] ?? ''),
                'description' => (string) ($hit['description'] ?? ''),
                'icon' => $hit['icon_url'] ?? null,
                'downloads' => (int) ($hit['downloads'] ?? 0),
                'url' => "https://modrinth.com/{$type->value}/".($hit['slug'] ?? $hit['project_id']),
            ], $result['hits'] ?? []),
        ];
    }

    public function versions(ContentType $type, Platform $platform, string $project): array
    {
        $query = [];
        $loaders = $this->loaders($type, $platform);

        if ($loaders !== []) {
            $query['loaders'] = json_encode($loaders);
        }

        if ($platform->minecraft !== null && $type !== ContentType::Modpack) {
            $query['game_versions'] = json_encode([$platform->minecraft]);
        }

        return array_map($this->version(...), Api::get(self::API.'/project/'.rawurlencode($project).'/version', $query, ttl: 300));
    }

    public function project(string $project): array
    {
        $data = Api::get(self::API.'/project/'.rawurlencode($project), ttl: 3600);

        return [
            'source' => $this->name(),
            'id' => (string) $data['id'],
            'slug' => (string) $data['slug'],
            'name' => (string) $data['title'],
            'author' => '',
            'description' => (string) ($data['description'] ?? ''),
            'icon' => $data['icon_url'] ?? null,
            'downloads' => (int) ($data['downloads'] ?? 0),
            'url' => "https://modrinth.com/{$data['project_type']}/{$data['slug']}",
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> Projects keyed by id.
     */
    public function projects(array $ids): array
    {
        $projects = [];

        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            foreach (Api::get(self::API.'/projects', ['ids' => json_encode($chunk)], ttl: 3600) as $project) {
                $projects[(string) $project['id']] = [
                    'id' => (string) $project['id'],
                    'name' => (string) $project['title'],
                    'icon' => $project['icon_url'] ?? null,
                    'url' => "https://modrinth.com/{$project['project_type']}/{$project['slug']}",
                ];
            }
        }

        return $projects;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> Versions keyed by id.
     */
    public function versionsById(array $ids): array
    {
        $versions = [];

        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            foreach (Api::get(self::API.'/versions', ['ids' => json_encode($chunk)], ttl: 3600) as $version) {
                $versions[(string) $version['id']] = $this->version($version);
            }
        }

        return $versions;
    }

    /** @return list<string> Projects this version cannot run without. */
    public function dependencies(string $version): array
    {
        $dependencies = Api::get(self::API.'/version/'.rawurlencode($version), ttl: 3600)['dependencies'] ?? [];

        return array_values(array_unique(array_filter(array_map(
            static fn (array $dependency): ?string => ($dependency['dependency_type'] ?? '') === 'required' ? ($dependency['project_id'] ?? null) : null,
            $dependencies,
        ))));
    }

    /**
     * Finds the versions that published these exact files.
     *
     * @param  list<string>  $sha1
     * @return array<string, array<string, mixed>> Versions keyed by file hash.
     */
    public function versionsByHash(array $sha1): array
    {
        if ($sha1 === []) {
            return [];
        }

        return array_map($this->version(...), Api::post(self::API.'/version_files', ['hashes' => $sha1, 'algorithm' => 'sha1']));
    }

    /**
     * @param  list<string>  $sha1
     * @return array<string, array<string, mixed>> The newest compatible version, keyed by the installed file's hash.
     */
    public function updates(array $sha1, ContentType $type, Platform $platform): array
    {
        if ($sha1 === []) {
            return [];
        }

        $body = ['hashes' => $sha1, 'algorithm' => 'sha1'];
        $loaders = $this->loaders($type, $platform);

        if ($loaders !== []) {
            $body['loaders'] = $loaders;
        }

        if ($platform->minecraft !== null) {
            $body['game_versions'] = [$platform->minecraft];
        }

        return array_map($this->version(...), Api::post(self::API.'/version_files/update', $body));
    }

    /**
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    private function version(array $version): array
    {
        $files = $version['files'] ?? [];
        $file = collect($files)->firstWhere('primary', true) ?? ($files[0] ?? null);

        return [
            'source' => $this->name(),
            'project' => (string) $version['project_id'],
            'id' => (string) $version['id'],
            'name' => (string) ($version['name'] ?? $version['version_number']),
            'number' => (string) ($version['version_number'] ?? ''),
            'type' => (string) ($version['version_type'] ?? 'release'),
            'game_versions' => array_values($version['game_versions'] ?? []),
            'loaders' => array_values($version['loaders'] ?? []),
            'published' => $version['date_published'] ?? null,
            'file' => $file === null ? null : [
                'url' => (string) $file['url'],
                'name' => (string) $file['filename'],
                'size' => (int) ($file['size'] ?? 0),
                'sha1' => $file['hashes']['sha1'] ?? null,
            ],
        ];
    }

    /** @return list<string> */
    private function loaders(ContentType $type, Platform $platform): array
    {
        return match ($type) {
            ContentType::Datapack => ['datapack'],
            ContentType::ResourcePack => ['minecraft'],
            ContentType::Modpack => [],
            default => $platform->loaders(),
        };
    }
}
