<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MinecraftContent\Content\ContentType;
use MinecraftContent\Content\Installed;
use MinecraftContent\Content\Installer;
use MinecraftContent\Content\Scanner;
use MinecraftContent\Panel\Panel;
use MinecraftContent\Platform\Detector;
use MinecraftContent\Support\Properties;
use Pterodactyl\Models\Server;

final class InstalledController extends Controller
{
    public function __construct(
        private readonly Installer $installer,
        private readonly Scanner $scanner,
        private readonly Detector $detector,
        private readonly Installed $installed,
        private readonly Panel $panel,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->fileType($request);

        return new JsonResponse(['files' => $this->installer->list($server, $type)]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $type = $this->type($request);
        abort_if($type === ContentType::Modpack, 422, 'Modpacks are installed from the Modpacks tab.');

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'hangar', 'spiget', 'curseforge'])],
            'project' => ['required', 'string', 'regex:'.self::PROJECT],
            'version' => ['nullable', 'string', 'regex:'.self::VERSION],
        ]);

        return $this->attempt(fn (): array => [
            'installed' => $this->installer->install($server, $this->detector->detect($server), $type, $data['source'], $data['project'], $data['version'] ?? null),
        ]);
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $type = $this->fileType($request);
        $this->installer->remove($server, $this->path($request, $server, $type));

        return new JsonResponse(['files' => $this->installer->list($server, $type)]);
    }

    public function toggle(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $type = $this->fileType($request);
        $this->installer->toggle($server, $this->path($request, $server, $type), $request->validate(['enabled' => ['required', 'boolean']])['enabled']);

        return new JsonResponse(['files' => $this->installer->list($server, $type)]);
    }

    public function updates(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->fileType($request);

        return $this->attempt(fn (): array => ['updates' => (object) $this->installer->updates($server, $this->detector->detect($server), $type)]);
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $type = $this->fileType($request);
        $path = $this->path($request, $server, $type);

        return $this->attempt(fn (): array => ['installed' => $this->installer->update($server, $this->detector->detect($server), $type, $path)]);
    }

    public function scan(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $type = $this->fileType($request);

        return $this->attempt(fn (): array => $this->scanner->scan($server, $type));
    }

    public function removeResourcePack(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');
        $properties = $this->panel->read($server, 'server.properties', 256 * 1024);

        if ($properties !== null) {
            $this->panel->write($server, 'server.properties', Properties::patch($properties, ['resource-pack' => '', 'resource-pack-sha1' => '']));
        }

        $this->installed->set($server, 'resource_pack', null);

        return new JsonResponse(['removed' => true]);
    }

    private function fileType(Request $request): ContentType
    {
        $type = $this->type($request);
        abort_unless(in_array($type, [ContentType::Plugin, ContentType::Mod, ContentType::Datapack], true), 422, 'This content type has no files.');

        return $type;
    }

    /** The requested file, which has to be directly inside the content directory. */
    private function path(Request $request, Server $server, ContentType $type): string
    {
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:300']])['path'];
        $directory = $this->installer->directory($server, $type);
        $name = basename($path);

        abort_unless($path === "{$directory}/{$name}" && preg_match('/^[\w.+() \-\[\]]+$/u', $name) === 1, 422, 'That file is not in the content folder.');

        return $path;
    }
}
