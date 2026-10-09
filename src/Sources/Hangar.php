<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;

/**
 * PaperMC's plugin repository.
 */
final class Hangar implements Source
{
    private const string API = 'https://hangar.papermc.io/api/v1';

    private const array SORTS = ['relevance' => '-downloads', 'downloads' => '-downloads', 'updated' => '-updated', 'newest' => '-newest'];

    public function name(): string
    {
        return 'hangar';
    }

    public function supports(ContentType $type, Platform $platform): bool
    {
        return $type === ContentType::Plugin && $platform->hangarPlatform() !== null;
    }

    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array
    {
        $parameters = [
            'q' => $query,
            'limit' => 20,
            'offset' => max(0, $page - 1) * 20,
            'platform' => $platform->hangarPlatform(),
            'sort' => self::SORTS[$sort] ?? '-downloads',
        ];

        if ($platform->minecraft !== null && $platform->kind() !== 'proxy') {
            $parameters['version'] = $platform->minecraft;
        }

        $result = Api::get(self::API.'/projects', array_filter($parameters, static fn (mixed $value): bool => $value !== '' && $value !== null), ttl: 300);

        return [
            'total' => (int) ($result['pagination']['count'] ?? 0),
            'projects' => array_map($this->project(...), $result['result'] ?? []),
        ];
    }

    public function versions(ContentType $type, Platform $platform, string $project): array
    {
        $parameters = ['limit' => 25, 'platform' => $platform->hangarPlatform()];

        if ($platform->minecraft !== null && $platform->kind() !== 'proxy') {
            $parameters['platformVersion'] = $platform->minecraft;
        }

        $result = Api::get(self::API.'/projects/'.rawurlencode($project).'/versions', $parameters, ttl: 300);
        $key = (string) $platform->hangarPlatform();

        return array_values(array_map(static function (array $version) use ($project, $key): array {
            $download = $version['downloads'][$key] ?? null;
            $url = $download['downloadUrl'] ?? $download['externalUrl'] ?? null;
            $channel = mb_strtolower((string) ($version['channel']['name'] ?? 'release'));

            return [
                'source' => 'hangar',
                'project' => $project,
                'id' => (string) $version['name'],
                'name' => (string) $version['name'],
                'number' => (string) $version['name'],
                'type' => match ($channel) {
                    'release' => 'release',
                    'alpha' => 'alpha',
                    default => 'beta',
                },
                'game_versions' => array_values($version['platformDependencies'][$key] ?? []),
                'loaders' => [mb_strtolower($key)],
                'published' => $version['createdAt'] ?? null,
                'file' => $url === null || ! isset($download['fileInfo']) ? null : [
                    'url' => (string) $url,
                    'name' => (string) $download['fileInfo']['name'],
                    'size' => (int) ($download['fileInfo']['sizeBytes'] ?? 0),
                    'sha1' => null,
                ],
            ];
        }, $result['result'] ?? []));
    }

    public function project(string|array $project): array
    {
        $data = is_array($project) ? $project : Api::get(self::API.'/projects/'.rawurlencode($project), ttl: 3600);
        $owner = (string) ($data['namespace']['owner'] ?? '');
        $slug = (string) ($data['namespace']['slug'] ?? $data['name'] ?? '');

        return [
            'source' => $this->name(),
            'id' => $slug,
            'slug' => $slug,
            'name' => (string) ($data['name'] ?? $slug),
            'author' => $owner,
            'description' => (string) ($data['description'] ?? ''),
            'icon' => $data['avatarUrl'] ?? null,
            'downloads' => (int) ($data['stats']['downloads'] ?? 0),
            'url' => "https://hangar.papermc.io/{$owner}/{$slug}",
        ];
    }
}
