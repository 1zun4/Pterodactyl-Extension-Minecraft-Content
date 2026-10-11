<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;
use MinecraftContent\Support\Text;

/**
 * SpigotMC resources through Spiget. Only free resources hosted on SpigotMC itself can be installed,
 * and only their latest version, because that is the one Spiget keeps a copy of.
 */
final class Spiget implements Source
{
    private const string API = 'https://api.spiget.org/v2';

    private const string FIELDS = 'id,name,tag,external,premium,file,icon,downloads,likes,testedVersions,releaseDate,updateDate';

    private const array SORTS = ['relevance' => '-downloads', 'downloads' => '-downloads', 'updated' => '-updateDate', 'newest' => '-releaseDate', 'follows' => '-likes'];

    private const array UNITS = ['KB' => 1024, 'MB' => 1024 * 1024, 'GB' => 1024 * 1024 * 1024];

    public function name(): string
    {
        return 'spiget';
    }

    public function supports(ContentType $type, Platform $platform): bool
    {
        return $type === ContentType::Plugin && $platform->kind() === 'plugins' && $platform->software !== 'folia';
    }

    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array
    {
        $parameters = ['size' => 20, 'page' => max(1, $page), 'sort' => self::SORTS[$sort] ?? '-downloads', 'fields' => self::FIELDS];
        $query = mb_trim($query);
        $result = $query === ''
            ? Api::get(self::API.'/resources/free', $parameters, ttl: 300)
            : Api::get(self::API.'/search/resources/'.rawurlencode($query), $parameters + ['field' => 'name'], ttl: 300);

        $installable = array_filter($result, static fn (mixed $resource): bool => is_array($resource) && ! ($resource['external'] ?? true) && ! ($resource['premium'] ?? true));

        return [
            // Spiget does not report a total; offer one more page while pages are full.
            'total' => ($page - 1) * 20 + count($result) + (count($result) === 20 ? 20 : 0),
            'projects' => array_values(array_map($this->project(...), $installable)),
        ];
    }

    public function versions(ContentType $type, Platform $platform, string $project): array
    {
        $resource = Api::get(self::API.'/resources/'.(int) $project, ttl: 3600);
        $latest = Api::get(self::API.'/resources/'.(int) $project.'/versions/latest', ttl: 600);
        $url = ! ($resource['external'] ?? true) && ! ($resource['premium'] ?? true) && ($resource['file']['type'] ?? '') === '.jar' ? $this->copy((int) $project) : null;
        $number = (string) ($latest['name'] ?? '');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '', html_entity_decode((string) ($resource['name'] ?? ''))) ?: 'plugin';

        return [[
            'source' => $this->name(),
            'project' => (string) (int) $project,
            'id' => (string) ($latest['id'] ?? ''),
            'name' => $number,
            'number' => $number,
            'type' => 'release',
            'game_versions' => array_values($resource['testedVersions'] ?? []),
            'loaders' => ['spigot', 'paper'],
            'published' => isset($latest['releaseDate']) ? date(DATE_ATOM, (int) $latest['releaseDate']) : null,
            'file' => $url !== null ? [
                'url' => $url,
                'name' => mb_trim("{$name}-".preg_replace('/[^A-Za-z0-9._-]+/', '', $number), '-').'.jar',
                // Spiget only knows a rounded size, so stay below it.
                'size' => (int) (($resource['file']['size'] ?? 0) * (self::UNITS[$resource['file']['sizeUnit'] ?? ''] ?? 0) * 0.9),
                'sha1' => null,
            ] : null,
        ]];
    }

    public function details(string $project): array
    {
        $data = Api::get(self::API.'/resources/'.(int) $project, ['fields' => 'description,likes,sourceCodeLink,donationLink,updateDate'], ttl: 3600);

        return [
            'description' => Text::plain((string) base64_decode((string) ($data['description'] ?? ''), true)),
            'followers' => (int) ($data['likes'] ?? 0),
            'updated' => isset($data['updateDate']) ? date(DATE_ATOM, (int) $data['updateDate']) : null,
            'license' => null,
            'categories' => [],
            'links' => Text::links(['Source code' => $data['sourceCodeLink'] ?? null, 'Donate' => $data['donationLink'] ?? null]),
            'gallery' => [],
        ];
    }

    public function project(string|array $project): array
    {
        $data = is_array($project) ? $project : Api::get(self::API.'/resources/'.(int) $project, ttl: 3600);
        $icon = (string) ($data['icon']['url'] ?? '');

        return [
            'source' => $this->name(),
            'id' => (string) ($data['id'] ?? ''),
            'slug' => (string) ($data['id'] ?? ''),
            'name' => html_entity_decode((string) ($data['name'] ?? '')),
            'author' => '',
            'description' => html_entity_decode((string) ($data['tag'] ?? '')),
            'icon' => $icon === '' ? null : "https://www.spigotmc.org/{$icon}",
            'downloads' => (int) ($data['downloads'] ?? 0),
            'followers' => (int) ($data['likes'] ?? 0),
            'url' => 'https://www.spigotmc.org/resources/'.(int) ($data['id'] ?? 0),
        ];
    }

    /** Spiget's own copy of the latest file; Wings does not follow the download redirect, and SpigotMC itself blocks servers. */
    private function copy(int $project): ?string
    {
        return Cache::remember("ext:mcc:spiget:{$project}", 600, static function () use ($project): ?string {
            $location = Http::withUserAgent(Api::USER_AGENT)->withoutRedirecting()->timeout(10)->head(self::API."/resources/{$project}/download")->header('Location');

            return str_starts_with($location, 'https://cdn.spiget.org/') ? $location : null;
        });
    }
}
