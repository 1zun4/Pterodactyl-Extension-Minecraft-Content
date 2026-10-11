<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MinecraftContent\Content\Installed;
use MinecraftContent\Plans\Planner;
use MinecraftContent\Plans\Plans;
use MinecraftContent\Platform\Detector;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use RuntimeException;

final class ModpackController extends Controller
{
    public function __construct(
        private readonly Planner $planner,
        private readonly Plans $plans,
        private readonly Detector $detector,
        private readonly Installed $installed,
    ) {}

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'modpacks');

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])],
            'project' => ['required', 'string', 'regex:'.self::PROJECT],
            'version' => ['required', 'string', 'regex:'.self::VERSION],
            'cleanup' => ['required', Rule::in(['smart', 'full', 'keep'])],
            'backup' => ['required', 'boolean'],
            'start' => ['required', 'boolean'],
        ]);

        return $this->attempt(function () use ($request, $server, $data): array {
            $plan = $this->planner->modpack($this->detector->detect($server), $data['source'], $data['project'], $data['version'], [
                'cleanup' => (string) $data['cleanup'],
                'backup' => (bool) $data['backup'],
                'start' => (bool) $data['start'],
            ], $this->installed->all($server)['modpack']['files'] ?? []);

            Activity::event('server:startup.edit')->subject($server)->property(['variable' => 'Modpack', 'old' => '', 'new' => mb_substr($plan['label'], mb_strlen('Installing '))])->log();

            return ['job' => $this->plans->start($server, $request->user(), 'ext.minecraft-content.modpacks', $plan['label'], $plan['steps'])];
        });
    }

    public function destroy(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'modpacks');
        $backup = (bool) $request->validate(['backup' => ['required', 'boolean']])['backup'];

        return $this->attempt(function () use ($request, $server, $backup): array {
            $modpack = $this->installed->all($server)['modpack'] ?? throw new RuntimeException('No modpack was installed from the panel.');
            $files = $modpack['files'] ?? throw new RuntimeException('This modpack was installed before the panel kept track of its files, so it cannot be removed here.');

            Activity::event('server:startup.edit')->subject($server)->property(['variable' => 'Modpack', 'old' => (string) $modpack['name'], 'new' => ''])->log();

            return ['job' => $this->plans->start($server, $request->user(), 'ext.minecraft-content.modpacks', "Removing {$modpack['name']}", $this->planner->uninstall((string) $modpack['name'], $files, $backup))];
        });
    }
}
