<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use RuntimeException;

/**
 * CurseForge needs an API key from console.curseforge.com, set in the extension's settings.
 */
final readonly class CurseForge implements Source
{
    private const string API = 'https://api.curseforge.com/v1';

    private const int MINECRAFT = 432;

    private const array CLASSES = ['mod' => 6, 'plugin' => 5, 'modpack' => 4471, 'resourcepack' => 12, 'datapack' => 6945];

    private const array LOADERS = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6];

    private const array SORTS = ['relevance' => 2, 'downloads' => 6, 'updated' => 3, 'newest' => 11];

    private const array RELEASE_TYPES = [1 => 'release', 2 => 'beta', 3 => 'alpha'];

    public function __construct(private ExtensionSettingsRegistry $settings) {}

    public function name(): string
    {
        return 'curseforge';
    }

    public function enabled(): bool
    {
        return $this->key() !== '';
    }

    public function supports(ContentType $type, Platform $platform): bool
    {
        return $this->enabled() && $platform->supports($type) && ($type !== ContentType::Plugin || $platform->kind() === 'plugins');
    }

    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array
    {
        $parameters = [
            'gameId' => self::MINECRAFT,
            'classId' => self::CLASSES[$type->value],
            'searchFilter' => $query,
            'sortField' => self::SORTS[$sort] ?? 2,
            'sortOrder' => 'desc',
            'pageSize' => 20,
            'index' => max(0, $page - 1) * 20,
        ];

        if ($platform->minecraft !== null && $type !== ContentType::Modpack) {
            $parameters['gameVersion'] = $platform->minecraft;
        }

        if (($loader = $this->loader($type, $platform)) !== null) {
            $parameters['modLoaderType'] = $loader;
        }

        $result = $this->get('/mods/search', array_filter($parameters, static fn (mixed $value): bool => $value !== ''));

        return [
            'total' => min(10000, (int) ($result['pagination']['totalCount'] ?? 0)),
            'projects' => array_map($this->normalizeProject(...), $result['data'] ?? []),
        ];
    }

    public function versions(ContentType $type, Platform $platform, string $project): array
    {
        $parameters = ['pageSize' => 50];

        if ($platform->minecraft !== null && $type !== ContentType::Modpack) {
            $parameters['gameVersion'] = $platform->minecraft;
        }

        if (($loader = $this->loader($type, $platform)) !== null) {
            $parameters['modLoaderType'] = $loader;
        }

        return array_map($this->file(...), $this->get('/mods/'.(int) $project.'/files', $parameters)['data'] ?? []);
    }

    public function project(string $project): array
    {
        return $this->normalizeProject($this->get('/mods/'.(int) $project)['data'] ?? []);
    }

    /** @return array<string, mixed> */
    public function version(string $project, string $file): array
    {
        return $this->file($this->get('/mods/'.(int) $project.'/files/'.(int) $file)['data'] ?? []);
    }

    /**
     * @param  list<int>  $files
     * @return list<array<string, mixed>>
     */
    public function files(array $files): array
    {
        if ($files === []) {
            return [];
        }

        return array_map($this->file(...), Api::post(self::API.'/mods/files', ['fileIds' => $files], $this->headers())['data'] ?? []);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, array<string, mixed>> Projects keyed by id.
     */
    public function projects(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $projects = [];

        foreach (Api::post(self::API.'/mods', ['modIds' => array_values(array_unique($ids))], $this->headers())['data'] ?? [] as $mod) {
            $projects[(string) $mod['id']] = $this->normalizeProject($mod);
        }

        return $projects;
    }

    /**
     * @param  list<int>  $fingerprints
     * @return array<int, array<string, mixed>> Versions keyed by fingerprint.
     */
    public function versionsByFingerprint(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $result = Api::post(self::API.'/fingerprints/'.self::MINECRAFT, ['fingerprints' => $fingerprints], $this->headers());
        $versions = [];

        foreach ($result['data']['exactMatches'] ?? [] as $match) {
            $versions[(int) $match['file']['fileFingerprint']] = $this->file($match['file']);
        }

        return $versions;
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function file(array $file): array
    {
        $sha1 = collect($file['hashes'] ?? [])->firstWhere('algo', 1)['value'] ?? null;
        $versions = array_values(array_filter($file['gameVersions'] ?? [], static fn (string $value): bool => preg_match('/^\d/', $value) === 1));
        $loaders = array_values(array_map('mb_strtolower', array_filter($file['gameVersions'] ?? [], static fn (string $value): bool => preg_match('/^\d/', $value) !== 1)));

        return [
            'source' => $this->name(),
            'project' => (string) ($file['modId'] ?? ''),
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['displayName'] ?? $file['fileName'] ?? ''),
            'number' => (string) ($file['displayName'] ?? ''),
            'type' => self::RELEASE_TYPES[(int) ($file['releaseType'] ?? 1)] ?? 'release',
            'game_versions' => $versions,
            'loaders' => $loaders,
            'published' => $file['fileDate'] ?? null,
            'server_pack' => isset($file['serverPackFileId']) ? (string) $file['serverPackFileId'] : null,
            'file' => empty($file['downloadUrl']) ? null : [
                'url' => (string) $file['downloadUrl'],
                'name' => (string) $file['fileName'],
                'size' => (int) ($file['fileLength'] ?? 0),
                'sha1' => $sha1,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $mod
     * @return array<string, mixed>
     */
    private function normalizeProject(array $mod): array
    {
        return [
            'source' => $this->name(),
            'id' => (string) ($mod['id'] ?? ''),
            'slug' => (string) ($mod['slug'] ?? ''),
            'name' => (string) ($mod['name'] ?? ''),
            'author' => (string) ($mod['authors'][0]['name'] ?? ''),
            'description' => (string) ($mod['summary'] ?? ''),
            'icon' => $mod['logo']['thumbnailUrl'] ?? null,
            'downloads' => (int) ($mod['downloadCount'] ?? 0),
            'url' => (string) ($mod['links']['websiteUrl'] ?? ''),
        ];
    }

    private function loader(ContentType $type, Platform $platform): ?int
    {
        return $type === ContentType::Mod ? (self::LOADERS[$platform->software] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return Api::get(self::API.$path, $query, $this->headers(), ttl: 300);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $key = $this->key();

        if ($key === '') {
            throw new RuntimeException('CurseForge needs an API key in the extension settings.');
        }

        return ['x-api-key' => $key];
    }

    private function key(): string
    {
        return (string) ($this->settings->get('minecraft-content')?->get('curseforge_api_key') ?? '');
    }
}
