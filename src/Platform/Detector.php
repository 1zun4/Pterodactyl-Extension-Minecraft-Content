<?php

declare(strict_types=1);

namespace MinecraftContent\Platform;

use Illuminate\Support\Facades\Cache;
use MinecraftContent\Panel\Panel;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Works out the server software from the files it leaves behind, and the Minecraft version from variables, folders and logs.
 */
final readonly class Detector
{
    private const array VERSION_VARIABLES = ['MINECRAFT_VERSION', 'MC_VERSION', 'VANILLA_VERSION', 'VERSION'];

    private const string VERSION = '/^\d+\.\d+(\.\d+)?$/';

    public function __construct(private Panel $panel) {}

    public function detect(Server $server): Platform
    {
        $override = Extensions::settings('minecraft-content')->forServer($server)->get('platform');

        if (is_array($override) && isset(Platform::SOFTWARE[$override['software'] ?? ''])) {
            return new Platform((string) $override['software'], isset($override['minecraft']) ? (string) $override['minecraft'] : null, detected: false);
        }

        [$software, $minecraft] = Cache::remember("ext:mcc:platform:{$server->uuid}", 60, function () use ($server): array {
            $root = array_column($this->panel->list($server, '/'), 'name');
            $software = $this->software($server, $root);

            return [$software, $this->minecraft($server, $root, $software)];
        });

        return new Platform($software, $minecraft);
    }

    public function override(Server $server, ?string $software, ?string $minecraft): void
    {
        $settings = Extensions::settings('minecraft-content')->forServer($server);
        Cache::forget("ext:mcc:platform:{$server->uuid}");

        $software === null
            ? $settings->forget('platform')
            : $settings->set('platform', ['software' => $software, 'minecraft' => $minecraft]);
    }

    /** NeoForge 21.1.x runs on Minecraft 1.21.1, 26.1.x on 26.1. */
    private static function neoforgeMinecraft(int $major, int $minor): string
    {
        if ($major >= 25) {
            return "{$major}.{$minor}";
        }

        return $minor === 0 ? "1.{$major}" : "1.{$major}.{$minor}";
    }

    /** @param list<string> $root */
    private function software(Server $server, array $root): string
    {
        $has = static fn (string ...$names): bool => array_intersect($names, $root) !== [];

        return match (true) {
            $has('velocity.toml') => 'velocity',
            $has('waterfall.yml', 'modules.yml') && $has('config.yml') => 'bungeecord',
            $this->panel->exists($server, 'libraries/net/neoforged') => 'neoforge',
            $this->panel->exists($server, 'libraries/net/minecraftforge') => 'forge',
            $has('quilt-server-launch.jar', '.quilt') => 'quilt',
            $has('.fabric', 'fabric-server-launch.jar', 'fabric-server-launcher.properties') => 'fabric',
            $has('purpur.yml') => 'purpur',
            $has('paper.yml') || $this->panel->exists($server, 'config/paper-global.yml') => $this->isFolia($server) ? 'folia' : 'paper',
            $has('spigot.yml', 'bukkit.yml') => 'spigot',
            $has('plugins') => 'paper',
            default => 'vanilla',
        };
    }

    private function isFolia(Server $server): bool
    {
        return preg_match('/folia/i', (string) ($this->panel->variables($server)['SERVER_JARFILE'] ?? '')) === 1
            || $this->panel->exists($server, 'config/folia-global.yml');
    }

    /** @param list<string> $root */
    private function minecraft(Server $server, array $root, string $software): ?string
    {
        if (in_array($software, ['velocity', 'bungeecord'], true)) {
            return null;
        }

        $variables = $this->panel->variables($server);

        foreach (self::VERSION_VARIABLES as $name) {
            $value = mb_trim($variables[$name] ?? '');

            if (preg_match(self::VERSION, $value) === 1) {
                return $value;
            }
        }

        try {
            $log = $this->panel->read($server, 'logs/latest.log', 4 * 1024 * 1024) ?? '';
        } catch (Throwable) {
            $log = '';
        }

        if (preg_match('/Starting minecraft server version (\d+\.\d+(?:\.\d+)?)/', $log, $match) === 1) {
            return $match[1];
        }

        $library = match ($software) {
            'forge' => ['libraries/net/minecraftforge/forge', '/^(\d+\.\d+(?:\.\d+)?)-/'],
            'neoforge' => ['libraries/net/neoforged/neoforge', '/^(\d+)\.(\d+)\./'],
            default => null,
        };

        if ($library !== null) {
            foreach (array_column($this->panel->list($server, $library[0]), 'name') as $name) {
                if (preg_match($library[1], $name, $match) === 1) {
                    return $software === 'neoforge' ? self::neoforgeMinecraft((int) $match[1], (int) $match[2]) : $match[1];
                }
            }
        }

        if (in_array('versions', $root, true)) {
            $versions = array_values(array_filter(
                array_column($this->panel->list($server, 'versions'), 'name'),
                static fn (string $name): bool => preg_match(self::VERSION, $name) === 1,
            ));

            if ($versions !== []) {
                usort($versions, version_compare(...));

                return end($versions);
            }
        }

        return null;
    }
}
