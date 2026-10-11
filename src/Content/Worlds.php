<?php

declare(strict_types=1);

namespace MinecraftContent\Content;

use MinecraftContent\Panel\Panel;
use MinecraftContent\Support\Properties;
use Pterodactyl\Models\Server;
use RuntimeException;

/**
 * The worlds in the server folder, which one the server loads, and their Nether and End.
 * Minecraft 26.1+ keeps the Nether and End in "<world>/dimensions/minecraft", older Paper and Spigot in "<world>_nether"
 * and "<world>_the_end", and older vanilla in "<world>/DIM-1" and "<world>/DIM1".
 */
final readonly class Worlds
{
    public const array TYPES = ['normal' => 'minecraft:normal', 'flat' => 'minecraft:flat', 'large_biomes' => 'minecraft:large_biomes', 'amplified' => 'minecraft:amplified'];

    public const string NAME = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    private const array SKIP = ['plugins', 'mods', 'config', 'libraries', 'logs', 'cache', 'versions', 'crash-reports', 'defaultconfigs', 'kubejs', 'resourcepacks', 'shaderpacks'];

    public function __construct(private Panel $panel) {}

    /** @return array{active: string, worlds: list<array<string, mixed>>} */
    public function all(Server $server): array
    {
        $active = $this->active($server);
        $folders = collect($this->panel->list($server, '/'))->reject(static fn (array $entry): bool => $entry['file'])->keyBy('name');
        $worlds = [];

        foreach ($folders as $name => $folder) {
            if (count($worlds) >= 40 || str_starts_with((string) $name, '.') || in_array($name, self::SKIP, true) || $this->isCompanion((string) $name, $folders->keys()->all())) {
                continue;
            }

            $children = collect($this->panel->list($server, (string) $name))->keyBy('name');

            if (! $children->has('level.dat')) {
                continue;
            }

            $worlds[] = [
                'name' => (string) $name,
                'active' => $name === $active,
                'pending' => false,
                'modified' => $folder['modified'] ?? null,
                ...$this->dimensions($server, (string) $name, $children->keys()->all(), $folders->keys()->all()),
                'split' => $folders->has("{$name}_nether") || $folders->has("{$name}_the_end"),
            ];
        }

        if (! collect($worlds)->contains('name', $active)) {
            array_unshift($worlds, ['name' => $active, 'active' => true, 'pending' => true, 'modified' => null, 'nether' => null, 'end' => null, 'split' => false]);
        }

        usort($worlds, static fn (array $a, array $b): int => [$b['active'], $a['name']] <=> [$a['active'], $b['name']]);

        return ['active' => $active, 'worlds' => $worlds];
    }

    public function active(Server $server): string
    {
        return mb_trim(Properties::parse($this->properties($server))['level-name'] ?? '', " \t/") ?: 'world';
    }

    public function activate(Server $server, string $name): void
    {
        $this->world($server, $name);
        $this->patch($server, ['level-name' => $name]);
    }

    /** The server generates the world on its next start. */
    public function create(Server $server, string $name, string $type, string $seed): void
    {
        if ($this->panel->exists($server, $name)) {
            throw new RuntimeException("A folder called {$name} already exists.");
        }

        $this->patch($server, ['level-name' => $name, 'level-type' => self::TYPES[$type] ?? self::TYPES['normal'], 'level-seed' => $seed]);
    }

    public function delete(Server $server, string $name): void
    {
        $world = $this->world($server, $name);

        if ($world['active']) {
            throw new RuntimeException('The world the server loads cannot be deleted. Switch to another world first.');
        }

        $this->offline($server);
        $this->panel->delete($server, '/', array_values(array_filter([$name, "{$name}_nether", "{$name}_the_end"], fn (string $folder): bool => $this->panel->exists($server, $folder))));
    }

    /** Deletes one dimension so the server generates it again. */
    public function reset(Server $server, string $name, string $dimension): void
    {
        $world = $this->world($server, $name);
        $this->offline($server);

        $path = $world[$dimension] ?? throw new RuntimeException('That dimension has not been generated yet.');

        $this->panel->delete($server, dirname($path), [basename($path)]);
    }

    /** @return array<string, mixed> */
    private function world(Server $server, string $name): array
    {
        return collect($this->all($server)['worlds'])->first(static fn (array $world): bool => $world['name'] === $name && ! $world['pending'])
            ?? throw new RuntimeException("There is no world called {$name}.");
    }

    /**
     * @param  list<string>  $children
     * @param  list<string>  $folders
     * @return array{nether: string|null, end: string|null} Where the server keeps each dimension, once generated.
     */
    private function dimensions(Server $server, string $name, array $children, array $folders): array
    {
        if (in_array('dimensions', $children, true)) {
            $generated = array_column($this->panel->list($server, "{$name}/dimensions/minecraft"), 'name');
            $path = static fn (string $folder): ?string => in_array($folder, $generated, true) ? "{$name}/dimensions/minecraft/{$folder}" : null;

            return ['nether' => $path('the_nether'), 'end' => $path('the_end')];
        }

        $path = static fn (string $split, string $inside): ?string => match (true) {
            in_array("{$name}_{$split}", $folders, true) => "{$name}_{$split}",
            in_array($inside, $children, true) => "{$name}/{$inside}",
            default => null,
        };

        return ['nether' => $path('nether', 'DIM-1'), 'end' => $path('the_end', 'DIM1')];
    }

    private function offline(Server $server): void
    {
        if (! in_array($this->panel->state($server), ['offline', 'stopped'], true)) {
            throw new RuntimeException('Stop the server first.');
        }
    }

    /** @param list<string> $folders */
    private function isCompanion(string $name, array $folders): bool
    {
        return preg_match('/^(.+)_(nether|the_end)$/', $name, $match) === 1 && in_array($match[1], $folders, true);
    }

    private function properties(Server $server): string
    {
        return $this->panel->read($server, 'server.properties', 256 * 1024) ?? '';
    }

    /** @param array<string, string> $values */
    private function patch(Server $server, array $values): void
    {
        $this->panel->write($server, 'server.properties', Properties::patch($this->properties($server), $values));
    }
}
