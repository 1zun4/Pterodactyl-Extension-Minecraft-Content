<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;
use MinecraftContent\Support\Text;

/**
 * Feed The Beast modpacks.
 */
final class Ftb implements Source
{
    private const string API = 'https://api.feed-the-beast.com/v1/modpacks/public/modpack';

    public function name(): string
    {
        return 'ftb';
    }

    public function supports(ContentType $type, Platform $platform): bool
    {
        return $type === ContentType::Modpack && $platform->kind() !== 'proxy';
    }

    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array
    {
        $limit = max(1, $page) * 20;
        $result = mb_trim($query) === ''
            ? Api::get(self::API."/popular/installs/{$limit}", ttl: 3600)
            : Api::get(self::API."/search/{$limit}", ['term' => $query], ttl: 600);
        $ids = array_slice($result['packs'] ?? [], ($page - 1) * 20, 20);

        return [
            'total' => (int) ($result['count'] ?? $result['total'] ?? count($result['packs'] ?? [])),
            'projects' => array_map(fn (int $id): array => $this->project((string) $id), $ids),
        ];
    }

    public function versions(ContentType $type, Platform $platform, string $project): array
    {
        $pack = $this->pack($project);

        return array_values(array_map(static function (array $version) use ($project): array {
            $targets = collect($version['targets'] ?? []);

            return [
                'source' => 'ftb',
                'project' => $project,
                'id' => (string) $version['id'],
                'name' => (string) $version['name'],
                'number' => (string) $version['name'],
                'type' => (string) ($version['type'] ?? 'release'),
                'game_versions' => $targets->where('type', 'game')->pluck('version')->values()->all(),
                'loaders' => $targets->where('type', 'modloader')->pluck('name')->values()->all(),
                'published' => isset($version['updated']) ? date(DATE_ATOM, (int) $version['updated']) : null,
                'file' => null,
            ];
        }, array_reverse($pack['versions'] ?? [])));
    }

    public function project(string $project): array
    {
        $pack = $this->pack($project);

        return [
            'source' => $this->name(),
            'id' => (string) $pack['id'],
            'slug' => (string) ($pack['slug'] ?? $pack['id']),
            'name' => (string) ($pack['name'] ?? ''),
            'author' => (string) ($pack['authors'][0]['name'] ?? 'FTB'),
            'description' => (string) ($pack['synopsis'] ?? ''),
            'icon' => collect($pack['art'] ?? [])->firstWhere('type', 'square')['url'] ?? null,
            'downloads' => (int) ($pack['installs'] ?? 0),
            'followers' => null,
            'url' => 'https://www.feed-the-beast.com/modpacks/'.($pack['slug'] ?? $pack['id']),
        ];
    }

    public function details(string $project): array
    {
        $pack = $this->pack($project);

        return [
            'description' => Text::plain((string) ($pack['description'] ?? $pack['synopsis'] ?? '')),
            'followers' => null,
            'updated' => isset($pack['updated']) ? date(DATE_ATOM, (int) $pack['updated']) : null,
            'license' => null,
            'categories' => array_values(array_filter(array_column($pack['tags'] ?? [], 'name'), is_string(...))),
            'links' => Text::links(array_column($pack['links'] ?? [], 'link', 'name')),
            'gallery' => Text::gallery(array_map(static fn (array $art): array => [$art['url'] ?? null, ''], array_values(array_filter($pack['art'] ?? [], static fn (array $art): bool => ($art['type'] ?? '') === 'screenshot')))),
        ];
    }

    /** @return array<array-key, mixed> The files and targets of one pack version. */
    public function manifest(string $project, string $version): array
    {
        return Api::get(self::API.'/'.(int) $project.'/'.(int) $version, ttl: 3600);
    }

    /** @return array<array-key, mixed> */
    private function pack(string $project): array
    {
        return Api::get(self::API.'/'.(int) $project, ttl: 3600);
    }
}
