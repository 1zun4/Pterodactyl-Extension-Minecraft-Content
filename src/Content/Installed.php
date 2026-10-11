<?php

declare(strict_types=1);

namespace MinecraftContent\Content;

use DateTimeImmutable;
use MinecraftContent\Panel\Panel;
use Pterodactyl\Models\Server;

/**
 * What this extension installed, kept in .mc-content.json in the server's root so it moves with backups and transfers.
 */
final readonly class Installed
{
    public const string FILE = '.mc-content.json';

    public function __construct(private Panel $panel) {}

    /** @return array{items: list<array<string, mixed>>, modpack: array<string, mixed>|null, resource_pack: array<string, mixed>|null, software: array<string, mixed>|null} */
    public function all(Server $server): array
    {
        $data = $this->panel->readJson($server, self::FILE) ?? [];

        return [
            'items' => array_values(array_filter($data['items'] ?? [], is_array(...))),
            'modpack' => is_array($data['modpack'] ?? null) ? $data['modpack'] : null,
            'resource_pack' => is_array($data['resource_pack'] ?? null) ? $data['resource_pack'] : null,
            'software' => is_array($data['software'] ?? null) ? $data['software'] : null,
        ];
    }

    /** @param array<string, mixed> $item */
    public function put(Server $server, array $item): void
    {
        $data = $this->all($server);
        $data['items'] = array_values(array_filter($data['items'], static fn (array $existing): bool => $existing['path'] !== $item['path']));
        $data['items'][] = $item + ['installed_at' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $this->save($server, $data);
    }

    /** @param list<array<string, mixed>> $items */
    public function putMany(Server $server, array $items): void
    {
        $paths = array_column($items, 'path');
        $data = $this->all($server);
        $data['items'] = array_values(array_filter($data['items'], static fn (array $existing): bool => ! in_array($existing['path'], $paths, true)));
        $installedAt = (new DateTimeImmutable())->format(DATE_ATOM);

        foreach ($items as $item) {
            $data['items'][] = $item + ['installed_at' => $installedAt];
        }

        $this->save($server, $data);
    }

    public function forget(Server $server, string $path): void
    {
        $data = $this->all($server);
        $data['items'] = array_values(array_filter($data['items'], static fn (array $item): bool => $item['path'] !== $path));
        $this->save($server, $data);
    }

    public function move(Server $server, string $from, string $to): void
    {
        $data = $this->all($server);
        $data['items'] = array_map(static fn (array $item): array => $item['path'] === $from ? ['path' => $to] + $item : $item, $data['items']);
        $this->save($server, $data);
    }

    /** @param array<string, mixed>|null $value */
    public function set(Server $server, string $key, ?array $value): void
    {
        $data = $this->all($server);
        $data[$key] = $value === null ? null : $value + ['installed_at' => (new DateTimeImmutable())->format(DATE_ATOM)];
        $this->save($server, $data);
    }

    /** @param array<string, mixed> $data */
    private function save(Server $server, array $data): void
    {
        $this->panel->write($server, self::FILE, json_encode(['version' => 1] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", log: false);
    }
}
