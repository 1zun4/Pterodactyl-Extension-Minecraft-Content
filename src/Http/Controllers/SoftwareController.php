<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MinecraftContent\Content\Installed;
use MinecraftContent\Panel\Panel;
use MinecraftContent\Plans\Planner;
use MinecraftContent\Plans\Plans;
use MinecraftContent\Platform\Detector;
use MinecraftContent\Sources\McJars;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;

final class SoftwareController extends Controller
{
    private const string TYPE = '/^[A-Z][A-Z0-9_]{1,31}$/';

    public function __construct(
        private readonly McJars $mcjars,
        private readonly Planner $planner,
        private readonly Plans $plans,
        private readonly Panel $panel,
        private readonly Detector $detector,
        private readonly Installed $installed,
    ) {}

    public function types(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        return $this->attempt(fn (): array => ['types' => array_values($this->mcjars->types())]);
    }

    public function versions(Request $request, Server $server, string $type): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        abort_unless(preg_match(self::TYPE, $type) === 1, 404);

        return $this->attempt(fn (): array => ['versions' => $this->mcjars->versions($type)]);
    }

    public function builds(Request $request, Server $server, string $type, string $version): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        abort_unless(preg_match(self::TYPE, $type) === 1 && preg_match(self::VERSION, $version) === 1, 404);

        return $this->attempt(fn (): array => ['builds' => array_map(
            static fn (array $build): array => array_diff_key($build, ['installation' => true]),
            array_slice($this->mcjars->builds($type, $version), 0, 50),
        )]);
    }

    /** The build installed through this extension and a newer stable build of the same version, if there is one. */
    public function current(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        return $this->attempt(function () use ($server): array {
            $installed = $this->installed->all($server)['software'];
            $platform = $this->detector->detect($server);

            // A jar swapped by hand makes the remembered build meaningless.
            if ($installed === null || mb_strtolower((string) $installed['type']) !== $platform->software || (string) $installed['version'] !== $platform->minecraft) {
                return ['installed' => null, 'update' => null];
            }

            $latest = collect($this->mcjars->builds((string) $installed['type'], (string) $installed['version']))->firstWhere('experimental', false);
            $update = $latest !== null && $latest['id'] !== (int) $installed['build'] ? array_diff_key($latest, ['installation' => true]) : null;

            return ['installed' => $installed, 'update' => $update];
        });
    }

    public function change(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'version');

        $data = $request->validate([
            'type' => ['required', 'string', 'regex:'.self::TYPE],
            'version' => ['required', 'string', 'regex:'.self::VERSION],
            'build' => ['nullable', 'integer', 'min:1'],
            'clean' => ['required', 'boolean'],
        ]);

        return $this->attempt(function () use ($request, $server, $data): array {
            $build = $this->mcjars->build($data['type'], $data['version'], $data['build'] ?? null);
            abort_if($build === null, 422, 'That build does not exist.');

            $running = ! in_array($this->panel->state($server), ['offline', 'stopped'], true);
            $label = ucfirst(mb_strtolower($data['type']))." {$data['version']} ({$build['name']})";
            $current = $this->detector->detect($server);

            Activity::event('server:startup.edit')->subject($server)->property([
                'variable' => 'Server software',
                'old' => mb_trim("{$current->software} {$current->minecraft}"),
                'new' => $label,
            ])->log();

            return ['job' => $this->plans->start(
                $server,
                $request->user(),
                'ext.minecraft-content.version',
                "Installing {$label}",
                $this->planner->version($data['type'], $data['version'], $build['id'], (bool) $data['clean'], $running),
            )];
        });
    }
}
