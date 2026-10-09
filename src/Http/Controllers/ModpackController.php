<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MinecraftContent\Plans\Planner;
use MinecraftContent\Plans\Plans;
use MinecraftContent\Platform\Detector;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;

final class ModpackController extends Controller
{
    public function __construct(private readonly Planner $planner, private readonly Plans $plans, private readonly Detector $detector) {}

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'modpacks');

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'curseforge', 'ftb'])],
            'project' => ['required', 'string', 'regex:'.self::PROJECT],
            'version' => ['required', 'string', 'regex:'.self::VERSION],
            'wipe' => ['required', 'boolean'],
            'backup' => ['required', 'boolean'],
            'start' => ['required', 'boolean'],
        ]);

        return $this->attempt(function () use ($request, $server, $data): array {
            $plan = $this->planner->modpack($this->detector->detect($server), $data['source'], $data['project'], $data['version'], [
                'wipe' => (bool) $data['wipe'],
                'backup' => (bool) $data['backup'],
                'start' => (bool) $data['start'],
            ]);

            Activity::event('server:startup.edit')->subject($server)->property(['variable' => 'Modpack', 'old' => '', 'new' => mb_substr($plan['label'], mb_strlen('Installing '))])->log();

            return ['job' => $this->plans->start($server, $request->user(), 'ext.minecraft-content.modpacks', $plan['label'], $plan['steps'])];
        });
    }
}
