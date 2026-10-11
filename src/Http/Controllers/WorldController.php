<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use MinecraftContent\Content\Worlds;
use MinecraftContent\Panel\Panel;
use MinecraftContent\Plans\Planner;
use MinecraftContent\Plans\Plans;
use MinecraftContent\Platform\Detector;
use MinecraftContent\Sources\Api;
use MinecraftContent\Sources\Sources;
use MinecraftContent\Support\Urls;
use Pterodactyl\Models\Server;
use RuntimeException;
use Throwable;

final class WorldController extends Controller
{
    private const string ARCHIVE = '/\.(zip|tar|tar\.gz|tgz)$/i';

    public function __construct(
        private readonly Worlds $worlds,
        private readonly Panel $panel,
        private readonly Planner $planner,
        private readonly Plans $plans,
        private readonly Detector $detector,
        private readonly Sources $sources,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        return $this->attempt(fn (): array => $this->worlds->all($server) + [
            'archives' => array_values(array_map(
                static fn (array $entry): array => ['name' => $entry['name'], 'size' => $entry['size']],
                array_filter($this->panel->list($server, '/'), static fn (array $entry): bool => $entry['file'] && preg_match(self::ARCHIVE, $entry['name']) === 1),
            )),
            'curseforge' => $this->sources->curseForge->enabled(),
            'state' => $this->panel->state($server),
        ]);
    }

    public function activate(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'worlds');

        return $this->attempt(function () use ($request, $server): array {
            $this->worlds->activate($server, $this->name($request));

            return $this->worlds->all($server);
        });
    }

    public function create(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'worlds');
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Worlds::TYPES))],
            'seed' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt(function () use ($request, $server, $data): array {
            $this->worlds->create($server, $this->name($request), $data['type'], (string) ($data['seed'] ?? ''));

            return $this->worlds->all($server);
        });
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'worlds');

        return $this->attempt(function () use ($request, $server): array {
            $this->worlds->delete($server, $this->name($request));

            return $this->worlds->all($server);
        });
    }

    public function reset(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'worlds');
        $dimension = (string) $request->validate(['dimension' => ['required', Rule::in(['nether', 'end'])]])['dimension'];

        return $this->attempt(function () use ($request, $server, $dimension): array {
            $this->worlds->reset($server, $this->name($request), $dimension);

            return $this->worlds->all($server);
        });
    }

    public function search(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $data = $request->validate(['query' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return $this->attempt(fn (): array => $this->sources->curseForge->worlds($this->detector->detect($server), (string) ($data['query'] ?? ''), (int) ($data['page'] ?? 1)));
    }

    /** Starts a job that unpacks a world from a link, an archive on the server or CurseForge. */
    public function import(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'worlds');
        $data = $request->validate([
            'source' => ['required', Rule::in(['url', 'file', 'curseforge'])],
            'url' => ['required_if:source,url', 'nullable', 'url:https', 'max:1000'],
            'file' => ['required_if:source,file', 'nullable', 'string', 'max:255', 'regex:'.self::ARCHIVE],
            'project' => ['required_if:source,curseforge', 'nullable', 'integer'],
            'activate' => ['required', 'boolean'],
        ]);
        $name = $this->name($request);

        return $this->attempt(function () use ($request, $server, $data, $name): array {
            if ($this->panel->exists($server, $name)) {
                throw new RuntimeException("A folder called {$name} already exists.");
            }

            [$url, $size, $file] = match ($data['source']) {
                'url' => [...$this->link((string) $data['url']), null],
                'file' => [null, 0, $this->archive($server, (string) $data['file'])],
                'curseforge' => [...$this->curseForgeFile((string) $data['project']), null],
            };

            return ['job' => $this->plans->start(
                $server,
                $request->user(),
                'ext.minecraft-content.worlds',
                "Installing the world {$name}",
                $this->planner->world($name, (bool) $data['activate'], $url, $file, $size),
            )];
        });
    }

    private function name(Request $request): string
    {
        return (string) $request->validate(['name' => ['required', 'string', 'regex:'.Worlds::NAME]])['name'];
    }

    /** @return array{0: string, 1: int} The direct download URL and its size. */
    private function link(string $url): array
    {
        try {
            $response = Http::withUserAgent(Api::USER_AGENT)->timeout(20)->head(Urls::direct($url));
        } catch (Throwable) {
            throw new RuntimeException('The link could not be reached.');
        }

        $size = (int) $response->header('Content-Length');

        if (! $response->successful() || $size <= 0) {
            throw new RuntimeException('The link has to point straight at a world archive.');
        }

        return [(string) ($response->effectiveUri() ?? $url), $size];
    }

    private function archive(Server $server, string $file): string
    {
        $file = basename($file);

        if (! $this->panel->exists($server, $file)) {
            throw new RuntimeException("{$file} is not in the server folder.");
        }

        return $file;
    }

    /** @return array{0: string, 1: int} */
    private function curseForgeFile(string $project): array
    {
        $file = collect($this->sources->curseForge->worldFiles($project))->first();

        if ($file === null || $file['file'] === null) {
            throw new RuntimeException('The author of this world does not allow downloads through other apps. Download it from CurseForge and upload it instead.');
        }

        return [(string) $file['file']['url'], (int) $file['file']['size']];
    }
}
