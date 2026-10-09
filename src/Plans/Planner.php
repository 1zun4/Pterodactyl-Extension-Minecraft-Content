<?php

declare(strict_types=1);

namespace MinecraftContent\Plans;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;
use MinecraftContent\Sources\Sources;
use RuntimeException;

/**
 * Turns "change the version" and "install this modpack" into plan steps.
 */
final readonly class Planner
{
    public const string WORK = '.mc-content/work';

    /** Folders a modpack brings that the previous pack's copies would conflict with. */
    private const array PACK_FOLDERS = ['mods', 'config', 'defaultconfigs', 'kubejs', 'scripts', 'libraries', '.fabric', '.quilt', 'versions', 'resourcepacks', 'shaderpacks'];

    /** Files of the old server software that a new version would trip over. */
    private const array SOFTWARE_FILES = ['libraries', 'versions', 'cache', '.fabric', '.quilt'];

    /** Server files a pack must not overwrite. */
    private const array KEEP = ['server.properties', 'eula.txt', 'ops.json', 'whitelist.json', 'banned-players.json', 'banned-ips.json', 'usercache.json'];

    private const array FTB_LOADERS = ['forge' => 'FORGE', 'neoforge' => 'NEOFORGE', 'fabric' => 'FABRIC', 'quilt' => 'QUILT'];

    public function __construct(private Sources $sources) {}

    /** @return list<array<string, mixed>> */
    public function version(string $type, string $version, ?int $build, bool $clean, bool $start): array
    {
        return array_values(array_filter([
            ['op' => 'stop'],
            $clean ? ['op' => 'clear', 'paths' => [...self::SOFTWARE_FILES, 'server.jar']] : null,
            ['op' => 'loader', 'type' => $type, 'minecraft' => $version, 'build' => $build],
            ['op' => 'redetect'],
            $start ? ['op' => 'start'] : null,
        ]));
    }

    /**
     * @param  array{wipe: bool, backup: bool, start: bool}  $options
     * @return array{label: string, steps: list<array<string, mixed>>}
     */
    public function modpack(Platform $platform, string $source, string $project, string $version, array $options): array
    {
        $details = $this->sources->get($source)->project($project);
        $chosen = collect($this->sources->get($source)->versions(ContentType::Modpack, $platform, $project))->firstWhere('id', $version)
            ?? throw new RuntimeException('That modpack version does not exist.');

        $steps = array_values(array_filter([
            ['op' => 'stop'],
            $options['backup'] ? ['op' => 'backup', 'name' => "Before {$details['name']}"] : null,
            $options['wipe'] ? ['op' => 'clear', 'paths' => self::PACK_FOLDERS] : null,
            ['op' => 'clear', 'paths' => [self::WORK]],
            ['op' => 'mkdir', 'path' => self::WORK],
        ]));

        array_push($steps, ...match ($source) {
            'modrinth' => $this->modrinth($chosen),
            'curseforge' => $this->curseForge($project, $chosen),
            'ftb' => $this->ftb($project, $chosen),
            default => throw new RuntimeException('Modpacks cannot be installed from this source.'),
        });

        array_push($steps, ...array_values(array_filter([
            ['op' => 'clear', 'paths' => [self::WORK]],
            ['op' => 'track', 'key' => 'modpack', 'value' => [
                'source' => $source,
                'project' => $details['id'],
                'version' => $chosen['id'],
                'name' => $details['name'],
                'version_name' => $chosen['number'] ?: $chosen['name'],
                'icon' => $details['icon'],
                'url' => $details['url'],
            ]],
            ['op' => 'redetect'],
            $options['start'] ? ['op' => 'start'] : null,
        ])));

        return ['label' => "Installing {$details['name']} {$chosen['name']}", 'steps' => $steps];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return list<array<string, mixed>>
     */
    private function modrinth(array $version): array
    {
        $file = $version['file'] ?? throw new RuntimeException('This modpack version has no file.');

        return [
            ['op' => 'pull', 'url' => $file['url'], 'directory' => self::WORK, 'filename' => 'pack.zip', 'size' => $file['size']],
            ['op' => 'decompress', 'root' => self::WORK, 'file' => 'pack.zip'],
            ['op' => 'mrpack', 'root' => self::WORK, 'keep' => self::KEEP],
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return list<array<string, mixed>>
     */
    private function curseForge(string $project, array $version): array
    {
        $client = $version['file'] ?? throw new RuntimeException('The author does not allow third-party downloads of this modpack.');
        $steps = [];
        $serverPack = null;

        if ($version['server_pack'] !== null) {
            $serverPack = $this->sources->curseForge->version($project, (string) $version['server_pack'])['file'];
        }

        if ($serverPack !== null) {
            $steps = [
                ['op' => 'pull', 'url' => $serverPack['url'], 'directory' => self::WORK.'/server', 'filename' => 'server.zip', 'size' => $serverPack['size']],
                ['op' => 'decompress', 'root' => self::WORK.'/server', 'file' => 'server.zip'],
                ['op' => 'clear', 'paths' => [self::WORK.'/server/server.zip']],
                ['op' => 'unwrap', 'from' => self::WORK.'/server', 'to' => '', 'keep' => self::KEEP],
            ];
        }

        return [
            ...$steps,
            ['op' => 'pull', 'url' => $client['url'], 'directory' => self::WORK.'/client', 'filename' => 'client.zip', 'size' => $client['size']],
            ['op' => 'decompress', 'root' => self::WORK.'/client', 'file' => 'client.zip'],
            ['op' => 'curseforge', 'root' => self::WORK.'/client', 'server_pack' => $serverPack !== null],
        ];
    }

    /**
     * @param  array<string, mixed>  $version
     * @return list<array<string, mixed>>
     */
    private function ftb(string $project, array $version): array
    {
        $manifest = $this->sources->ftb->manifest($project, (string) $version['id']);
        $steps = [];

        foreach ($manifest['files'] ?? [] as $file) {
            $path = Steps::safePath(mb_rtrim((string) ($file['path'] ?? ''), '/').'/'.($file['name'] ?? ''));

            if (($file['clientonly'] ?? false) || $path === null || empty($file['url'])) {
                continue;
            }

            $directory = dirname($path) === '.' ? '' : dirname($path);

            if ($directory === '' && in_array(basename($path), self::KEEP, true)) {
                continue;
            }

            $steps[] = ['op' => 'pull', 'url' => $file['url'], 'directory' => $directory, 'filename' => basename($path), 'size' => (int) ($file['size'] ?? 0)];
        }

        $targets = collect($manifest['targets'] ?? []);
        $minecraft = $targets->firstWhere('type', 'game')['version'] ?? null;
        $loader = $targets->firstWhere('type', 'modloader');

        if ($minecraft !== null) {
            $steps[] = [
                'op' => 'loader',
                'type' => self::FTB_LOADERS[$loader['name'] ?? ''] ?? 'VANILLA',
                'minecraft' => $minecraft,
                'project_version' => $loader['version'] ?? null,
            ];
        }

        return $steps;
    }
}
