<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

/**
 * Server jars for every common server software, from versions.mcjars.app.
 */
final class McJars
{
    private const string API = 'https://versions.mcjars.app/api/v2';

    /** @return array<string, array<string, mixed>> */
    public function types(): array
    {
        $types = [];

        foreach (Api::get(self::API.'/types', ttl: 3600)['types'] ?? [] as $group) {
            foreach ($group as $id => $type) {
                $types[(string) $id] = [
                    'id' => (string) $id,
                    'name' => (string) $type['name'],
                    'icon' => 'https://s3.mcjars.app/icons/'.mb_strtolower((string) $id).'.png',
                    'categories' => $type['categories'] ?? [],
                    'deprecated' => (bool) ($type['deprecated'] ?? false),
                    'experimental' => (bool) ($type['experimental'] ?? false),
                ];
            }
        }

        return $types;
    }

    /** @return list<array{id: string, type: string, java: int|null, supported: bool, latest: string|null}> Newest first. */
    public function versions(string $type): array
    {
        $versions = [];

        foreach (Api::get(self::API.'/builds/'.rawurlencode($type), ttl: 1800)['builds'] ?? [] as $id => $version) {
            $versions[] = [
                'id' => (string) $id,
                'type' => mb_strtolower((string) ($version['type'] ?? 'release')),
                'java' => isset($version['java']) ? (int) $version['java'] : null,
                'supported' => (bool) ($version['supported'] ?? true),
                'latest' => isset($version['latest']['name']) ? (string) $version['latest']['name'] : null,
            ];
        }

        return array_reverse($versions);
    }

    /** @return list<array<string, mixed>> Newest first. */
    public function builds(string $type, string $version): array
    {
        return array_map(static fn (array $build): array => [
            'id' => (int) $build['id'],
            'name' => (string) ($build['name'] ?? $build['buildNumber'] ?? ''),
            'project_version' => $build['projectVersionId'] ?? null,
            'experimental' => (bool) ($build['experimental'] ?? false),
            'size' => (int) ($build['jarSize'] ?? $build['zipSize'] ?? 0),
            'installation' => $build['installation'] ?? [],
            'created' => $build['created'] ?? null,
            'changes' => array_values(array_slice(array_filter($build['changes'] ?? [], is_string(...)), 0, 10)),
        ], Api::get(self::API.'/builds/'.rawurlencode($type).'/'.rawurlencode($version), ttl: 1800)['builds'] ?? []);
    }

    /** @return array<string, mixed>|null */
    public function build(string $type, string $version, ?int $id = null, ?string $projectVersion = null): ?array
    {
        foreach ($this->builds($type, $version) as $build) {
            if (($id === null || $build['id'] === $id) && ($projectVersion === null || $build['project_version'] === $projectVersion)) {
                return $build;
            }
        }

        return null;
    }

    public function java(string $type, string $version): ?int
    {
        return collect($this->versions($type))->firstWhere('id', $version)['java'] ?? null;
    }
}
