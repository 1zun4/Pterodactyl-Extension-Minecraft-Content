<?php

declare(strict_types=1);

namespace MinecraftContent\Content;

use Illuminate\Support\Facades\Cache;
use MinecraftContent\Panel\Panel;
use MinecraftContent\Sources\Sources;
use MinecraftContent\Support\Murmur2;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Recognises files that were uploaded by hand by their hashes, so they can be updated like everything else.
 */
final readonly class Scanner
{
    private const int MAX_FILE_BYTES = 48 * 1024 * 1024;

    private const int TIME_BUDGET_SECONDS = 20;

    public function __construct(private Panel $panel, private Installed $installed, private Installer $installer, private Sources $sources) {}

    /** @return array{recognised: list<string>, unknown: list<string>, remaining: int} */
    public function scan(Server $server, ContentType $type): array
    {
        $started = microtime(true);
        $key = "ext:mcc:scanned:{$server->uuid}";
        $known = (array) Cache::get($key, []);
        $pending = array_values(array_filter(
            $this->installer->list($server, $type),
            static fn (array $file): bool => ! $file['tracked'] && ! isset($known[$file['path'].':'.$file['size']]),
        ));

        $hashes = [];
        $sizes = [];

        foreach ($pending as $index => $file) {
            if (microtime(true) - $started > self::TIME_BUDGET_SECONDS) {
                break;
            }

            $sizes[$file['path']] = $file['size'];
            $contents = $file['size'] <= self::MAX_FILE_BYTES ? $this->panel->read($server, dirname($file['path']).'/'.$file['file'], self::MAX_FILE_BYTES) : null;
            $hashes[$file['path']] = $contents === null ? null : [
                'sha1' => sha1($contents),
                'fingerprint' => $this->sources->curseForge->enabled() ? Murmur2::curseForge($contents) : null,
            ];
            unset($contents, $pending[$index]);
        }

        $recognised = $this->recognise($server, $type, array_filter($hashes));
        $unknown = array_values(array_diff(array_keys($hashes), $recognised));

        foreach ($unknown as $path) {
            $known[$path.':'.$sizes[$path]] = true;
        }

        Cache::put($key, $known, 86400);

        return ['recognised' => $recognised, 'unknown' => $unknown, 'remaining' => count($pending)];
    }

    /**
     * @param  array<string, array{sha1: string, fingerprint: int|null}>  $hashes
     * @return list<string> Paths that were recognised.
     */
    private function recognise(Server $server, ContentType $type, array $hashes): array
    {
        $recognised = [];
        $modrinth = $this->sources->modrinth->versionsByHash(array_values(array_column($hashes, 'sha1')));

        foreach ($hashes as $path => $hash) {
            $version = $modrinth[$hash['sha1']] ?? null;

            if ($version !== null && $this->track($server, $type, $path, 'modrinth', $version, $hash['sha1'])) {
                $recognised[] = $path;
            }
        }

        $fingerprints = array_filter(array_column(array_diff_key($hashes, array_flip($recognised)), 'fingerprint', 'sha1'));

        if ($fingerprints !== []) {
            $curseForge = $this->sources->curseForge->versionsByFingerprint(array_values($fingerprints));

            foreach ($hashes as $path => $hash) {
                $version = $curseForge[$hash['fingerprint'] ?? -1] ?? null;

                if ($version !== null && $this->track($server, $type, $path, 'curseforge', $version, $hash['sha1'])) {
                    $recognised[] = $path;
                }
            }
        }

        return $recognised;
    }

    /** @param array<string, mixed> $version */
    private function track(Server $server, ContentType $type, string $path, string $source, array $version, string $sha1): bool
    {
        try {
            $project = $this->sources->get($source)->project((string) $version['project']);
        } catch (Throwable) {
            return false;
        }

        $this->installed->put($server, ['sha1' => $sha1] + $this->installer->item($type, $path, $project, $version));

        return true;
    }
}
