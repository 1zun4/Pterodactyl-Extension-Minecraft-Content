<?php

declare(strict_types=1);

namespace MinecraftContent\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MinecraftContent\Content\ContentType;
use Pterodactyl\Models\Server;
use RuntimeException;

abstract class Controller
{
    protected const string PROJECT = '/^[\w.-]{1,96}$/';

    protected const string VERSION = '/^[\w.+-]{1,96}$/';

    protected function authorize(Request $request, Server $server, string $permission): void
    {
        abort_unless((bool) $request->user()?->can("ext.minecraft-content.{$permission}", $server), 403, 'You do not have permission to do that.');
    }

    protected function type(Request $request): ContentType
    {
        return ContentType::tryFrom((string) $request->input('type')) ?? abort(422, 'Unknown content type.');
    }

    /** Turns failures from content APIs and plans into readable errors. */
    protected function attempt(Closure $callback): JsonResponse
    {
        try {
            return new JsonResponse($callback());
        } catch (RuntimeException $exception) {
            abort(422, $exception->getMessage());
        }
    }
}
