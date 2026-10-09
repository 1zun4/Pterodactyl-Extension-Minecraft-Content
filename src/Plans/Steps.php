<?php

declare(strict_types=1);

namespace MinecraftContent\Plans;

use Illuminate\Support\Facades\Http;
use MinecraftContent\Content\Installed;
use MinecraftContent\Panel\Panel;
use MinecraftContent\Platform\Detector;
use MinecraftContent\Sources\Api;
use MinecraftContent\Sources\McJars;
use MinecraftContent\Sources\Sources;
use MinecraftContent\Support\Urls;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use RuntimeException;
use Throwable;

/**
 * Runs one plan step. Steps are plain arrays with an "op" key so plans survive in the cache between jobs.
 */
final readonly class Steps
{
    /** Downloads below this size run while the job waits; larger ones are polled. */
    private const int FOREGROUND_LIMIT = 24 * 1024 * 1024;

    private const int DOWNLOAD_DEADLINE = 900;

    private const int STOP_DEADLINE = 120;

    private const array LOADERS = [
        'fabric-loader' => 'FABRIC',
        'quilt-loader' => 'QUILT',
        'forge' => 'FORGE',
        'neoforge' => 'NEOFORGE',
        'fabric' => 'FABRIC',
        'quilt' => 'QUILT',
    ];

    public function __construct(
        private Panel $panel,
        private McJars $mcjars,
        private Sources $sources,
        private Installed $installed,
        private Detector $detector,
    ) {}

    /** @param array<string, mixed> $step */
    public static function describe(array $step): string
    {
        return match ($step['op']) {
            'stop' => 'Stopped the server.',
            'start' => 'Started the server.',
            'backup' => 'Created a backup.',
            'clear' => 'Removed old files.',
            'pull' => 'Downloaded '.basename((string) $step['filename']).'.',
            'decompress' => 'Unpacked '.basename((string) $step['file']).'.',
            'move', 'unwrap' => 'Copied files into place.',
            'variables' => 'Updated the startup variables.',
            'loader' => 'Picked the server software.',
            'mrpack', 'curseforge' => 'Read the modpack.',
            default => 'Working...',
        };
    }

    /** Relative paths only, without leaving the server's folder. */
    public static function safePath(string $path): ?string
    {
        $path = preg_replace('#^\./#', '', str_replace('\\', '/', $path));

        if ($path === '' || str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }

    /** @param array<string, mixed> $plan */
    public function run(Server $server, array $step, array &$plan): StepResult
    {
        return match ($step['op']) {
            'stop' => $this->stop($server, $step),
            'start' => $this->start($server),
            'backup' => $this->backup($server, $step, $plan),
            'clear' => $this->clear($server, $step),
            'mkdir' => $this->mkdir($server, $step),
            'pull' => $this->pull($server, $step),
            'decompress' => $this->decompress($server, $step),
            'move' => $this->move($server, $step),
            'unwrap' => $this->unwrap($server, $step),
            'variables' => $this->variables($server, $step),
            'loader' => $this->loader($step),
            'mrpack' => $this->mrpack($server, $step, $plan),
            'curseforge' => $this->curseForge($server, $step, $plan),
            'track' => $this->track($server, $step),
            'track-items' => $this->trackItems($server, $step),
            'redetect' => $this->redetect($server),
            default => throw new RuntimeException("Unknown step \"{$step['op']}\"."),
        };
    }

    /** CurseForge names NeoForge 1.20.1 builds "1.20.1-47.1.x"; MCJars uses the part after the dash. */
    private static function loaderVersion(string $loader, string $version, string $minecraft): string
    {
        return str_starts_with($version, $minecraft.'-') ? mb_substr($version, mb_strlen($minecraft) + 1) : $version;
    }

    private static function relative(string $path): string
    {
        $path = mb_rtrim((string) preg_replace('#^\./#', '', $path), '/');

        return $path === '.' ? '' : $path;
    }

    /** @param array<string, mixed> $step */
    private function stop(Server $server, array $step): StepResult
    {
        if (in_array($this->panel->state($server), ['offline', 'stopped'], true)) {
            return StepResult::done();
        }

        if (! isset($step['deadline'])) {
            $this->panel->power($server, 'stop');

            return StepResult::wait(3, ['deadline' => time() + self::STOP_DEADLINE] + $step);
        }

        if (time() > $step['deadline']) {
            $this->panel->power($server, 'kill');
        }

        return StepResult::wait(3, $step);
    }

    private function start(Server $server): StepResult
    {
        $this->panel->power($server, 'start');

        return StepResult::done();
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $plan
     */
    private function backup(Server $server, array $step, array &$plan): StepResult
    {
        if (! isset($step['uuid'])) {
            $backup = $this->panel->backup($server, (string) $step['name']);

            if ($backup === null) {
                $plan['warnings'][] = 'No backup was made because the backup limit is reached.';

                return StepResult::done();
            }

            return StepResult::wait(5, ['uuid' => $backup->uuid, 'deadline' => time() + 3600] + $step);
        }

        $backup = Backup::query()->where('uuid', $step['uuid'])->first();

        if ($backup?->completed_at !== null) {
            return $backup->is_successful ? StepResult::done() : throw new RuntimeException('The backup failed, so nothing was changed.');
        }

        if (time() > $step['deadline']) {
            throw new RuntimeException('The backup did not finish within an hour.');
        }

        return StepResult::wait(5, $step);
    }

    /** @param array<string, mixed> $step */
    private function clear(Server $server, array $step): StepResult
    {
        $byDirectory = [];

        foreach ($step['paths'] as $path) {
            if ($this->panel->exists($server, $path)) {
                $byDirectory[dirname((string) $path) === '.' ? '/' : dirname((string) $path)][] = basename((string) $path);
            }
        }

        foreach ($byDirectory as $directory => $names) {
            $this->panel->delete($server, $directory, $names);
        }

        return StepResult::done();
    }

    /** @param array<string, mixed> $step */
    private function mkdir(Server $server, array $step): StepResult
    {
        $this->panel->ensureDirectory($server, (string) $step['path']);

        return StepResult::done();
    }

    /** @param array<string, mixed> $step */
    private function pull(Server $server, array $step): StepResult
    {
        $path = mb_trim("{$step['directory']}/{$step['filename']}", '/');
        $size = (int) ($step['size'] ?? 0);
        $step['url'] = Urls::direct((string) $step['url']);

        if (! isset($step['deadline'])) {
            if ($size < self::FOREGROUND_LIMIT) {
                try {
                    $this->panel->pull($server, $step['url'], (string) $step['directory'], (string) $step['filename'], true, log: false);
                } catch (DaemonConnectionException) {
                    // Wings refuses downloads without a Content-Length header, which some CDNs (FTB) do not send.
                    $this->download($server, $step, $path);
                }

                return StepResult::done();
            }

            $step['url'] = $this->resolve($step['url'], (string) $step['filename']);
            $this->panel->pull($server, $step['url'], (string) $step['directory'], (string) $step['filename'], false, log: false);

            return StepResult::wait(3, ['deadline' => time() + self::DOWNLOAD_DEADLINE, 'started' => time()] + $step);
        }

        $this->panel->list($server, dirname($path) === '.' ? '/' : dirname($path), fresh: true);

        $entry = $this->panel->entry($server, $path);

        if (($entry['size'] ?? 0) >= $size) {
            return StepResult::done();
        }

        if ($entry === null && time() - (int) ($step['started'] ?? time()) > 90) {
            throw new RuntimeException("The server could not download {$step['filename']}.");
        }

        if (time() > $step['deadline']) {
            throw new RuntimeException("Downloading {$step['filename']} took too long.");
        }

        return StepResult::wait(3, $step);
    }

    /**
     * Follows redirects, which Wings will not, and makes sure the file announces its size.
     */
    private function resolve(string $url, string $name): string
    {
        $response = Http::withUserAgent(Api::USER_AGENT)->timeout(20)->head($url);

        if (! $response->successful() || $response->header('Content-Length') === '') {
            throw new RuntimeException("{$name} cannot be downloaded by the server.");
        }

        return (string) ($response->effectiveUri() ?? $url);
    }

    /** @param array<string, mixed> $step */
    private function download(Server $server, array $step, string $path): void
    {
        $response = Http::withUserAgent(Api::USER_AGENT)->timeout(40)->get((string) $step['url']);

        if (! $response->successful() || mb_strlen($response->body(), '8bit') > self::FOREGROUND_LIMIT) {
            throw new RuntimeException("Could not download {$step['filename']}.");
        }

        $this->panel->ensureDirectory($server, (string) $step['directory']);
        $this->panel->write($server, $path, $response->body(), log: false);
    }

    /** @param array<string, mixed> $step */
    private function decompress(Server $server, array $step): StepResult
    {
        $this->panel->decompress($server, (string) $step['root'], (string) $step['file']);

        return StepResult::done();
    }

    /**
     * Merges one directory into another. Missing folders move in one rename; existing ones are merged file by file.
     *
     * @param  array<string, mixed>  $step
     */
    private function move(Server $server, array $step): StepResult
    {
        $from = mb_trim((string) $step['from'], '/');
        $to = mb_trim((string) $step['to'], '/');
        $next = [];

        if (! $this->panel->exists($server, $from)) {
            return StepResult::done();
        }

        $this->panel->ensureDirectory($server, $to);
        $existing = collect($this->panel->list($server, $to))->keyBy('name');
        $keep = $step['keep'] ?? [];

        foreach ($this->panel->list($server, $from) as $entry) {
            $source = "{$from}/{$entry['name']}";
            $target = mb_ltrim("{$to}/{$entry['name']}", '/');
            $current = $existing->get($entry['name']);

            if ($current !== null && in_array($entry['name'], $keep, true)) {
                continue;
            }

            if (! $entry['file'] && $current !== null && ! $current['file']) {
                $next[] = ['op' => 'move', 'from' => $source, 'to' => $target];

                continue;
            }

            if ($current !== null) {
                $this->panel->delete($server, $to === '' ? '/' : $to, [$entry['name']], log: false);
            }

            $this->panel->rename($server, '/', $source, $target);
        }

        return StepResult::then($next);
    }

    /**
     * Server packs often wrap everything in one top-level folder; move what is inside it.
     *
     * @param  array<string, mixed>  $step
     */
    private function unwrap(Server $server, array $step): StepResult
    {
        $entries = $this->panel->list($server, (string) $step['from']);
        $from = count($entries) === 1 && ! $entries[0]['file'] ? "{$step['from']}/{$entries[0]['name']}" : (string) $step['from'];

        return StepResult::then([['op' => 'move', 'from' => $from, 'to' => $step['to'], 'keep' => $step['keep'] ?? []]]);
    }

    /** @param array<string, mixed> $step */
    private function variables(Server $server, array $step): StepResult
    {
        $image = null;

        if (isset($step['java'])) {
            foreach ($this->panel->images($server) as $label => $candidate) {
                if (preg_match('/(?:java|jdk|jre)[\s_:-]*'.(int) $step['java'].'\b/i', $label.' '.$candidate) === 1) {
                    $image = $candidate;

                    break;
                }
            }
        }

        $this->panel->updateStartup($server, $step['environment'] ?? [], $image);

        return StepResult::done();
    }

    /**
     * Expands into the downloads MCJars lists for a server software build.
     *
     * @param  array<string, mixed>  $step
     */
    private function loader(array $step): StepResult
    {
        $type = (string) $step['type'];
        $build = $this->mcjars->build($type, (string) $step['minecraft'], isset($step['build']) ? (int) $step['build'] : null, $step['project_version'] ?? null)
            ?? $this->mcjars->build($type, (string) $step['minecraft']);

        // NeoForge for 1.20.1 is a Forge fork that MCJars does not list; its mods run on Forge.
        if ($build === null && $type === 'NEOFORGE') {
            $type = 'FORGE';
            $build = $this->mcjars->build($type, (string) $step['minecraft']);
        }

        if ($build === null) {
            throw new RuntimeException("MCJars has no {$type} build for Minecraft {$step['minecraft']}.");
        }

        $steps = [];

        foreach ($build['installation'] as $group) {
            foreach ($group as $action) {
                $location = self::relative((string) ($action['location'] ?? $action['file'] ?? ''));

                $steps[] = match ($action['type']) {
                    'download' => ['op' => 'pull', 'url' => $action['url'], 'directory' => dirname($location) === '.' ? '' : dirname($location), 'filename' => basename($location), 'size' => (int) ($action['size'] ?? 0)],
                    'remove' => ['op' => 'clear', 'paths' => [$location]],
                    'unzip' => ['op' => 'decompress', 'root' => $location === '' ? '/' : $location, 'file' => basename((string) $action['file'])],
                    default => throw new RuntimeException("Unknown MCJars step \"{$action['type']}\"."),
                };
            }
        }

        $steps[] = [
            'op' => 'variables',
            'environment' => ['SERVER_JARFILE' => 'server.jar', 'MINECRAFT_VERSION' => $step['minecraft'], 'BUILD_NUMBER' => 'latest'],
            'java' => $this->mcjars->java($type, (string) $step['minecraft']),
        ];

        return StepResult::then($steps, "Installing {$type} {$step['minecraft']}.");
    }

    /**
     * Reads a Modrinth pack's index and expands into its downloads, overrides and loader.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $plan
     */
    private function mrpack(Server $server, array $step, array &$plan): StepResult
    {
        $root = (string) $step['root'];
        $index = $this->panel->readJson($server, "{$root}/modrinth.index.json")
            ?? throw new RuntimeException('The modpack has no modrinth.index.json.');
        $steps = [];
        $tracked = [];

        foreach ($index['files'] ?? [] as $file) {
            if (($file['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = self::safePath((string) ($file['path'] ?? ''));
            $url = $file['downloads'][0] ?? null;

            if ($path === null || ! is_string($url)) {
                $plan['warnings'][] = 'Some files of the pack were skipped.';

                continue;
            }

            $steps[] = ['op' => 'pull', 'url' => $url, 'directory' => dirname($path) === '.' ? '' : dirname($path), 'filename' => basename($path), 'size' => (int) ($file['fileSize'] ?? 0)];

            if (preg_match('#^https://cdn\.modrinth\.com/data/(\w+)/versions/(\w+)/#', $url, $match) === 1 && in_array(dirname($path), ['mods', 'plugins'], true)) {
                $tracked[] = ['path' => $path, 'project' => $match[1], 'version' => $match[2], 'sha1' => $file['hashes']['sha1'] ?? null];
            }
        }

        $steps[] = ['op' => 'track-items', 'items' => $this->modrinthItems($tracked)];
        $steps[] = ['op' => 'move', 'from' => "{$root}/overrides", 'to' => '', 'keep' => $step['keep'] ?? []];
        $steps[] = ['op' => 'move', 'from' => "{$root}/server-overrides", 'to' => '', 'keep' => $step['keep'] ?? []];

        $dependencies = $index['dependencies'] ?? [];
        $loader = collect(self::LOADERS)->keys()->first(static fn (string $name): bool => isset($dependencies[$name]));
        $minecraft = (string) ($dependencies['minecraft'] ?? '');

        if ($minecraft !== '') {
            $steps[] = ['op' => 'loader', 'type' => $loader === null ? 'VANILLA' : self::LOADERS[$loader], 'minecraft' => $minecraft, 'project_version' => $loader === null ? null : $dependencies[$loader]];
        }

        return StepResult::then($steps, 'Found '.count($index['files'] ?? []).' files in the modpack.');
    }

    /**
     * Reads a CurseForge pack's manifest. Mods are downloaded unless a server pack already brought them.
     *
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $plan
     */
    private function curseForge(Server $server, array $step, array &$plan): StepResult
    {
        $root = (string) $step['root'];
        $manifest = $this->panel->readJson($server, "{$root}/manifest.json")
            ?? throw new RuntimeException('The modpack has no manifest.json.');
        $steps = [];

        if (! ($step['server_pack'] ?? false)) {
            $ids = array_values(array_filter(array_map(static fn (array $file): int => (int) ($file['fileID'] ?? 0), $manifest['files'] ?? [])));
            $versions = [];

            foreach (array_chunk($ids, 50) as $chunk) {
                foreach ($this->sources->curseForge->files($chunk) as $version) {
                    if ($version['file'] === null || self::safePath('mods/'.$version['file']['name']) === null) {
                        $plan['warnings'][] = 'Some mods do not allow third-party downloads and were skipped.';

                        continue;
                    }

                    $steps[] = ['op' => 'pull', 'url' => $version['file']['url'], 'directory' => 'mods', 'filename' => $version['file']['name'], 'size' => $version['file']['size']];
                    $versions[] = $version;
                }
            }

            $steps[] = ['op' => 'track-items', 'items' => $this->curseForgeItems($versions)];

            $steps[] = ['op' => 'move', 'from' => "{$root}/".($manifest['overrides'] ?? 'overrides'), 'to' => ''];
        }

        $minecraft = (string) ($manifest['minecraft']['version'] ?? '');
        $primary = collect($manifest['minecraft']['modLoaders'] ?? [])->firstWhere('primary', true) ?? ($manifest['minecraft']['modLoaders'][0] ?? null);

        if ($minecraft !== '' && preg_match('/^(forge|neoforge|fabric|quilt)-(.+)$/', (string) ($primary['id'] ?? ''), $match) === 1) {
            $steps[] = ['op' => 'loader', 'type' => self::LOADERS[$match[1]], 'minecraft' => $minecraft, 'project_version' => self::loaderVersion($match[1], $match[2], $minecraft)];
        }

        return StepResult::then($steps);
    }

    /** @param array<string, mixed> $step */
    private function track(Server $server, array $step): StepResult
    {
        $this->installed->set($server, (string) $step['key'], $step['value']);

        return StepResult::done();
    }

    /** @param array<string, mixed> $step */
    private function trackItems(Server $server, array $step): StepResult
    {
        if ($step['items'] !== []) {
            $this->installed->putMany($server, $step['items']);
        }

        return StepResult::done();
    }

    /**
     * @param  list<array{path: string, project: string, version: string, sha1: string|null}>  $files
     * @return list<array<string, mixed>>
     */
    private function modrinthItems(array $files): array
    {
        try {
            $projects = $this->sources->modrinth->projects(array_column($files, 'project'));
            $versions = $this->sources->modrinth->versionsById(array_column($files, 'version'));
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static function (array $file) use ($projects, $versions): ?array {
            $project = $projects[$file['project']] ?? null;

            return $project === null ? null : [
                'type' => str_starts_with($file['path'], 'plugins/') ? 'plugin' : 'mod',
                'path' => $file['path'],
                'source' => 'modrinth',
                'project' => $file['project'],
                'version' => $file['version'],
                'name' => $project['name'],
                'version_name' => $versions[$file['version']]['number'] ?? null,
                'icon' => $project['icon'],
                'url' => $project['url'],
                'sha1' => $file['sha1'],
            ];
        }, $files)));
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     * @return list<array<string, mixed>>
     */
    private function curseForgeItems(array $versions): array
    {
        try {
            $projects = $this->sources->curseForge->projects(array_map(static fn (array $version): int => (int) $version['project'], $versions));
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(static function (array $version) use ($projects): ?array {
            $project = $projects[$version['project']] ?? null;

            return $project === null ? null : [
                'type' => 'mod',
                'path' => 'mods/'.$version['file']['name'],
                'source' => 'curseforge',
                'project' => $version['project'],
                'version' => $version['id'],
                'name' => $project['name'],
                'version_name' => $version['number'],
                'icon' => $project['icon'],
                'url' => $project['url'],
                'sha1' => $version['file']['sha1'],
            ];
        }, $versions)));
    }

    private function redetect(Server $server): StepResult
    {
        $this->detector->override($server, null, null);

        return StepResult::done();
    }
}
