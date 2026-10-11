<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use MinecraftContent\Content\ContentType;
use MinecraftContent\Content\Installed;
use MinecraftContent\Plans\Plans;
use MinecraftContent\Platform\Detector;
use MinecraftContent\Platform\Platform;
use MinecraftContent\Sources\Sources;
use Pterodactyl\Models\Server;

final class ContentController extends Controller
{
    public function __construct(
        private readonly Detector $detector,
        private readonly Sources $sources,
        private readonly Installed $installed,
        private readonly Plans $plans,
    ) {}

    public function detect(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $platform = $this->detector->detect($server);
        $installed = $this->installed->all($server);

        return new JsonResponse([
            'platform' => $platform->toArray(),
            'types' => array_values(array_map(fn (ContentType $type): array => [
                'type' => $type->value,
                'supported' => $platform->supports($type),
                'sources' => $this->sources->available($type, $platform),
            ], ContentType::cases())),
            'software' => array_keys(Platform::SOFTWARE),
            'modpack' => $installed['modpack'] === null ? null : Arr::except($installed['modpack'], 'files') + [
                'file_count' => isset($installed['modpack']['files']) ? count($installed['modpack']['files']) : null,
            ],
            'resource_pack' => $installed['resource_pack'],
            'job' => $this->plans->active($server),
        ]);
    }

    public function override(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'version');

        $data = $request->validate([
            'software' => ['nullable', Rule::in(array_keys(Platform::SOFTWARE))],
            'minecraft' => ['nullable', 'string', 'regex:/^\d+\.\d+(\.\d+)?$/'],
        ]);

        $this->detector->override($server, $data['software'] ?? null, $data['minecraft'] ?? null);

        return $this->detect($request, $server);
    }

    public function cancel(Request $request, Server $server): JsonResponse
    {
        abort_unless(
            $request->user()?->can('ext.minecraft-content.modpacks', $server) || $request->user()?->can('ext.minecraft-content.version', $server),
            403,
            'You do not have permission to do that.',
        );

        $this->plans->cancel($server);

        return new JsonResponse(['cancelled' => true]);
    }

    public function search(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->type($request);

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'hangar', 'spiget', 'curseforge', 'ftb'])],
            'query' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['relevance', 'downloads', 'updated', 'newest', 'follows'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return $this->attempt(fn (): array => $this->sources->get($data['source'])->search(
            $type,
            $this->detector->detect($server),
            (string) ($data['query'] ?? ''),
            (string) ($data['sort'] ?? 'relevance'),
            (int) ($data['page'] ?? 1),
        ));
    }

    public function details(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'hangar', 'spiget', 'curseforge', 'ftb'])],
            'project' => ['required', 'string', 'regex:'.self::PROJECT],
        ]);

        return $this->attempt(fn (): array => $this->sources->get($data['source'])->details($data['project']));
    }

    public function versions(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $type = $this->type($request);

        $data = $request->validate([
            'source' => ['required', Rule::in(['modrinth', 'hangar', 'spiget', 'curseforge', 'ftb'])],
            'project' => ['required', 'string', 'regex:'.self::PROJECT],
        ]);

        return $this->attempt(fn (): array => [
            'versions' => $this->sources->get($data['source'])->versions($type, $this->detector->detect($server), $data['project']),
        ]);
    }
}
