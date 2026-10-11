<?php

declare(strict_types=1);

namespace MinecraftContent\Panel;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Contracts\Servers\ChangesServerEgg;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Contracts\Servers\UpdatesServerStartup;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

/**
 * The only place this extension touches Panel internals. Bound per request, so directory listings are cached.
 */
final class Panel
{
    /** @var array<string, list<array{name: string, size: int, file: bool, modified: string|null}>> */
    private array $listings = [];

    public function __construct(
        private readonly ReadsFileContents $reader,
        private readonly WritesFileContents $writer,
        private readonly ListsDirectories $lister,
        private readonly DeletesFiles $deleter,
        private readonly RenamesFiles $renamer,
        private readonly PullsFiles $puller,
        private readonly DecompressesFiles $decompressor,
        private readonly CreatesDirectories $directories,
        private readonly SendsServerPower $power,
        private readonly UpdatesServerStartup $startup,
        private readonly InitiatesBackups $backups,
        private readonly ChangesServerEgg $eggs,
    ) {}

    public function read(Server $server, string $path, int $maxBytes): ?string
    {
        return $this->exists($server, $path) ? $this->reader->read($server, $path, $maxBytes) : null;
    }

    /** @return array<array-key, mixed>|null */
    public function readJson(Server $server, string $path, int $maxBytes = 8 * 1024 * 1024): ?array
    {
        $contents = $this->read($server, $path, $maxBytes);
        $decoded = $contents === null ? null : json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function write(Server $server, string $path, string $contents, bool $log = true): void
    {
        $this->writer->write($server, $path, $contents);
        $this->forget($server, self::parent($path));

        if ($log) {
            Activity::event('server:file.write')->subject($server)->property('file', '/'.self::trim($path))->log();
        }
    }

    public function exists(Server $server, string $path): bool
    {
        $path = self::trim($path);

        return $path === '' || $this->entry($server, $path) !== null;
    }

    /** @return array{name: string, size: int, file: bool, modified: string|null}|null */
    public function entry(Server $server, string $path): ?array
    {
        $path = self::trim($path);

        foreach ($this->list($server, self::parent($path)) as $entry) {
            if ($entry['name'] === basename($path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Lists a directory, or returns an empty list when it does not exist.
     *
     * @return list<array{name: string, size: int, file: bool, modified: string|null}>
     */
    public function list(Server $server, string $directory, bool $fresh = false): array
    {
        $directory = self::trim($directory);
        $key = $server->uuid.':'.$directory;

        if (! $fresh && isset($this->listings[$key])) {
            return $this->listings[$key];
        }

        try {
            $entries = $this->lister->list($server, '/'.$directory);
        } catch (DaemonConnectionException $exception) {
            // Wings answers 500 for missing directories, so check the parent before treating it as an error.
            if ($directory !== '' && ! $this->exists($server, $directory)) {
                return $this->listings[$key] = [];
            }

            throw $exception;
        }

        return $this->listings[$key] = array_map(static fn (array $entry): array => [
            'name' => (string) ($entry['name'] ?? ''),
            'size' => (int) ($entry['size'] ?? 0),
            'file' => (bool) ($entry['file'] ?? false),
            'modified' => isset($entry['modified']) ? (string) $entry['modified'] : null,
        ], $entries);
    }

    /** @param list<string> $files */
    public function delete(Server $server, string $root, array $files, bool $log = true): void
    {
        if ($files === []) {
            return;
        }

        $this->deleter->delete($server, '/'.self::trim($root), $files);
        $this->forget($server, $root);

        if (! $log) {
            return;
        }

        Activity::event('server:file.delete')->subject($server)->property('directory', '/'.self::trim($root))->property('files', $files)->log();
    }

    public function rename(Server $server, string $root, string $from, string $to): void
    {
        $this->renamer->rename($server, '/'.self::trim($root), [['from' => $from, 'to' => $to]]);
        $this->forget($server, $root);
        $this->forget($server, self::parent(self::trim($root).'/'.$to));
    }

    public function pull(Server $server, string $url, string $directory, string $filename, bool $foreground, bool $log = true): void
    {
        $this->ensureDirectory($server, $directory);
        $this->puller->pull($server, $url, '/'.self::trim($directory), ['filename' => $filename, 'foreground' => $foreground, 'timeout' => 50]);
        $this->forget($server, $directory);

        if ($log) {
            Activity::event('server:file.pull')->subject($server)->property('directory', '/'.self::trim($directory))->property('url', $url)->log();
        }
    }

    public function decompress(Server $server, string $root, string $file): void
    {
        $this->decompressor->decompress($server, '/'.self::trim($root), $file);
        $this->forget($server, $root);
    }

    public function ensureDirectory(Server $server, string $directory): void
    {
        $directory = self::trim($directory);

        if ($directory === '' || $this->exists($server, $directory)) {
            return;
        }

        $this->ensureDirectory($server, self::parent($directory));
        $this->directories->create($server, basename($directory), '/'.self::parent($directory));
        $this->forget($server, self::parent($directory));
    }

    public function power(Server $server, string $signal): void
    {
        $this->power->send($server, $signal);
    }

    public function state(Server $server): string
    {
        try {
            return (string) (Daemon::server($server)->details()['state'] ?? 'offline');
        } catch (DaemonConnectionException) {
            return 'offline';
        }
    }

    /** @return array<string, string> */
    public function variables(Server $server): array
    {
        $values = [];

        foreach ($server->variables()->get() as $variable) {
            $values[(string) $variable->env_variable] = (string) ($variable->server_value ?? $variable->default_value);
        }

        return $values;
    }

    /**
     * The first Minecraft egg with one of these names, compared case-insensitively.
     *
     * @param  list<string>  $names
     */
    public function eggNamed(array $names): ?Egg
    {
        $eggs = Egg::query()->whereIn(DB::raw('LOWER(name)'), array_map(mb_strtolower(...), $names))->get()
            ->filter(static fn (Egg $egg): bool => in_array('eula', (array) $egg->features, true));

        foreach ($names as $name) {
            if ($egg = $eggs->first(static fn (Egg $egg): bool => mb_strtolower($egg->name) === mb_strtolower($name))) {
                return $egg;
            }
        }

        return null;
    }

    /** Keeps the variables both eggs share; the startup command and image become the new egg's. */
    public function changeEgg(Server $server, Egg $egg): void
    {
        $old = $server->egg->name;
        $this->eggs->change($server, $egg, true);
        $server->refresh();

        Activity::event('server:startup.edit')->subject($server)->property(['variable' => 'Egg', 'old' => $old, 'new' => $egg->name])->log();
    }

    /** @param array<string, string> $environment Only variables the egg defines are kept. */
    public function updateStartup(Server $server, array $environment, ?string $image = null): void
    {
        // The panel validates every required variable, so unchanged ones are sent along with their current values.
        $current = $this->variables($server);
        $known = array_merge($current, array_intersect_key($environment, $current));
        $images = array_values((array) $server->egg->docker_images);

        $this->startup->update($server, array_filter([
            'environment' => $known,
            'skip_scripts' => (bool) $server->skip_scripts,
            'docker_image' => $image !== null && in_array($image, $images, true) ? $image : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []), User::USER_LEVEL_ADMIN);
    }

    /** @return array<string, string> Docker image names keyed by their label, such as "Java 21". */
    public function images(Server $server): array
    {
        return array_map(strval(...), (array) $server->egg->docker_images);
    }

    public function backup(Server $server, string $name): ?Backup
    {
        if ($server->backup_limit <= $server->backups()->count()) {
            return null;
        }

        return $this->backups->initiate($server, $name);
    }

    private static function parent(string $path): string
    {
        $parent = dirname(self::trim($path));

        return $parent === '.' ? '' : $parent;
    }

    private static function trim(string $path): string
    {
        return mb_trim($path, '/');
    }

    private function forget(Server $server, string $directory): void
    {
        unset($this->listings[$server->uuid.':'.self::trim($directory)]);
    }
}
