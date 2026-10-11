<?php

declare(strict_types=1);

namespace MinecraftContent\Content;

use MinecraftContent\Panel\Panel;
use MinecraftContent\Platform\Platform;
use MinecraftContent\Sources\Sources;
use MinecraftContent\Support\Properties;
use MinecraftContent\Support\Urls;
use Pterodactyl\Models\Server;
use RuntimeException;
use Throwable;

/**
 * Installs, updates and removes plugins, mods, datapacks and resource packs.
 */
final readonly class Installer
{
    public const string DISABLED = '.disabled';

    /** Files below this size download while the request waits; larger ones keep downloading after it returns. */
    private const int FOREGROUND_LIMIT = 32 * 1024 * 1024;

    public function __construct(private Panel $panel, private Installed $installed, private Sources $sources) {}

    public function directory(Server $server, ContentType $type): string
    {
        return match ($type) {
            ContentType::Plugin => 'plugins',
            ContentType::Mod => 'mods',
            ContentType::Datapack => $this->level($server).'/datapacks',
            default => throw new RuntimeException("{$type->value} content has no directory."),
        };
    }

    /**
     * Every file in the content directory, with what is known about it.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Server $server, ContentType $type): array
    {
        $directory = $this->directory($server, $type);
        $tracked = collect($this->installed->all($server)['items'])->keyBy('path');
        $files = [];

        foreach ($this->panel->list($server, $directory, fresh: true) as $entry) {
            $enabledName = str_ends_with($entry['name'], self::DISABLED) ? mb_substr($entry['name'], 0, -mb_strlen(self::DISABLED)) : $entry['name'];

            if (! $entry['file'] || ! self::hasExtension($enabledName, $type)) {
                continue;
            }

            $path = "{$directory}/{$enabledName}";
            $item = $tracked->get($path) ?? $tracked->get("{$path}".self::DISABLED);

            $files[] = [
                'path' => $path,
                'file' => $entry['name'],
                'size' => $entry['size'],
                'modified' => $entry['modified'],
                'enabled' => $enabledName === $entry['name'],
                'tracked' => $item !== null,
                'source' => $item['source'] ?? null,
                'project' => $item['project'] ?? null,
                'version' => $item['version'] ?? null,
                'name' => $item['name'] ?? $enabledName,
                'version_name' => $item['version_name'] ?? null,
                'icon' => $item['icon'] ?? null,
                'url' => $item['url'] ?? null,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $files;
    }

    /**
     * @return list<array<string, mixed>> The installed items, dependencies included.
     */
    public function install(Server $server, Platform $platform, ContentType $type, string $source, string $project, ?string $versionId = null, bool $dependencies = true): array
    {
        if ($type === ContentType::ResourcePack) {
            return [$this->installResourcePack($server, $platform, $source, $project, $versionId)];
        }

        $version = $this->pick($server, $platform, $type, $source, $project, $versionId);
        $details = $this->sources->get($source)->project($project);
        $directory = $this->directory($server, $type);
        $file = $version['file'];
        $previous = collect($this->installed->all($server)['items'])->first(
            static fn (array $item): bool => $item['source'] === $source && $item['project'] === $details['id'] && $item['type'] === $type->value,
        );

        $this->panel->pull($server, Urls::direct($file['url']), $directory, $file['name'], $file['size'] < self::FOREGROUND_LIMIT);

        if ($previous !== null && $previous['path'] !== "{$directory}/{$file['name']}") {
            $this->removeFile($server, $previous['path']);
            $this->installed->forget($server, $previous['path']);
        }

        $item = $this->item($type, "{$directory}/{$file['name']}", $details, $version);
        $this->installed->put($server, $item);
        $items = [$item];

        if ($dependencies && $source === 'modrinth') {
            foreach ($this->missingDependencies($server, $version) as $dependency) {
                try {
                    array_push($items, ...$this->install($server, $platform, $type, 'modrinth', $dependency, dependencies: false));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $items;
    }

    public function remove(Server $server, string $path): void
    {
        $this->removeFile($server, $path);
        $this->installed->forget($server, $path);
    }

    /** Turns a file on or off by adding or removing the .disabled suffix the server ignores. */
    public function toggle(Server $server, string $path, bool $enabled): void
    {
        $directory = dirname($path);
        $name = basename($path);

        $enabled
            ? $this->panel->rename($server, $directory, $name.self::DISABLED, $name)
            : $this->panel->rename($server, $directory, $name, $name.self::DISABLED);
    }

    /**
     * Newest compatible versions for tracked items that are out of date.
     *
     * @return array<string, array<string, mixed>> Versions keyed by item path.
     */
    public function updates(Server $server, Platform $platform, ContentType $type): array
    {
        $items = array_filter($this->installed->all($server)['items'], static fn (array $item): bool => $item['type'] === $type->value);
        $updates = [];

        $modrinth = array_filter($items, static fn (array $item): bool => $item['source'] === 'modrinth' && ! empty($item['sha1']));
        $latest = $this->sources->modrinth->updates(array_values(array_column($modrinth, 'sha1')), $type, $platform);

        foreach ($modrinth as $item) {
            $version = $latest[$item['sha1']] ?? null;

            // Modrinth suggests betas too; installs prefer releases, so updates do as well.
            if ($version !== null && ($version['type'] ?? 'release') !== 'release') {
                try {
                    $version = $this->newest($this->sources->modrinth->versions($type, $platform, (string) $item['project']));
                } catch (Throwable) {
                    continue;
                }
            }

            // The same file can be listed under several versions, so the hash decides.
            if ($version !== null && $version['file'] !== null && $version['id'] !== $item['version'] && $version['file']['sha1'] !== $item['sha1']) {
                $updates[$item['path']] = $version;
            }
        }

        foreach ($items as $item) {
            if (! in_array($item['source'], ['hangar', 'spiget', 'curseforge'], true)) {
                continue;
            }

            try {
                $version = $this->newest($this->sources->get($item['source'])->versions($type, $platform, (string) $item['project']));
            } catch (Throwable) {
                continue;
            }

            if ($version !== null && $version['id'] !== $item['version']) {
                $updates[$item['path']] = $version;
            }
        }

        return $updates;
    }

    /** @return array<string, mixed> */
    public function update(Server $server, Platform $platform, ContentType $type, string $path): array
    {
        $item = collect($this->installed->all($server)['items'])->firstWhere('path', $path)
            ?? throw new RuntimeException('This file was not installed by the panel.');
        $version = $this->updates($server, $platform, $type)[$path] ?? throw new RuntimeException("{$item['name']} is up to date.");

        return $this->install($server, $platform, $type, (string) $item['source'], (string) $item['project'], (string) $version['id'], dependencies: false)[0];
    }

    /**
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>
     */
    public function item(ContentType $type, string $path, array $project, array $version): array
    {
        return [
            'type' => $type->value,
            'path' => $path,
            'source' => $version['source'],
            'project' => $project['id'],
            'version' => $version['id'],
            'name' => $project['name'],
            'version_name' => $version['number'] ?: $version['name'],
            'icon' => $project['icon'],
            'url' => $project['url'],
            'sha1' => $version['file']['sha1'] ?? null,
        ];
    }

    public function level(Server $server): string
    {
        $level = mb_trim(Properties::parse($this->panel->read($server, 'server.properties', 256 * 1024) ?? '')['level-name'] ?? '', " \t/");

        return $level !== '' && preg_match('#^[\w .\-/]+$#u', $level) === 1 && ! str_contains($level, '..') ? $level : 'world';
    }

    private static function hasExtension(string $name, ContentType $type): bool
    {
        foreach ($type->extensions() as $extension) {
            if (str_ends_with(mb_strtolower($name), $extension)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function installResourcePack(Server $server, Platform $platform, string $source, string $project, ?string $versionId): array
    {
        $version = $this->pick($server, $platform, ContentType::ResourcePack, $source, $project, $versionId);
        $details = $this->sources->get($source)->project($project);
        $properties = $this->panel->read($server, 'server.properties', 256 * 1024)
            ?? throw new RuntimeException('Start the server once so it creates server.properties.');

        $this->panel->write($server, 'server.properties', Properties::patch($properties, [
            'resource-pack' => $version['file']['url'],
            'resource-pack-sha1' => (string) ($version['file']['sha1'] ?? ''),
        ]));

        $item = $this->item(ContentType::ResourcePack, 'server.properties', $details, $version);
        $this->installed->set($server, 'resource_pack', $item);

        return $item;
    }

    /** @return array<string, mixed> */
    private function pick(Server $server, Platform $platform, ContentType $type, string $source, string $project, ?string $versionId): array
    {
        if (! $platform->supports($type) || ! $this->sources->get($source)->supports($type, $platform)) {
            throw new RuntimeException('This server cannot use that content.');
        }

        $versions = $this->sources->get($source)->versions($type, $platform, $project);
        $version = $versionId === null
            ? $this->newest($versions)
            : collect($versions)->firstWhere('id', $versionId);

        if ($version === null) {
            throw new RuntimeException('No version of this project fits the server'.($platform->minecraft ? " ({$platform->minecraft})" : '').'.');
        }

        if ($version['file'] === null) {
            throw new RuntimeException('The author does not allow downloads outside of their site. Download it from the project page instead.');
        }

        if (preg_match('/^[\w.+() \-\[\]]+$/u', $version['file']['name']) !== 1) {
            throw new RuntimeException('The file name of this version is not safe to use.');
        }

        return $version;
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     * @return array<string, mixed>|null
     */
    private function newest(array $versions): ?array
    {
        $downloadable = array_values(array_filter($versions, static fn (array $version): bool => $version['file'] !== null));

        return collect($downloadable)->firstWhere('type', 'release') ?? ($downloadable[0] ?? null);
    }

    /**
     * @param  array<string, mixed>  $version
     * @return list<string> Required Modrinth projects that are not installed yet.
     */
    private function missingDependencies(Server $server, array $version): array
    {
        $installed = array_column($this->installed->all($server)['items'], 'project');

        return array_values(array_diff($this->sources->modrinth->dependencies((string) $version['id']), $installed));
    }

    private function removeFile(Server $server, string $path): void
    {
        $directory = dirname($path);
        $names = array_column($this->panel->list($server, $directory), 'name');
        $this->panel->delete($server, $directory, array_values(array_intersect([basename($path), basename($path).self::DISABLED], $names)));
    }
}
