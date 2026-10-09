<?php

declare(strict_types=1);

namespace MinecraftContent\Content;

enum ContentType: string
{
    case Plugin = 'plugin';
    case Mod = 'mod';
    case Datapack = 'datapack';
    case ResourcePack = 'resourcepack';
    case Modpack = 'modpack';

    /** File extensions the server loads from the content directory. */
    public function extensions(): array
    {
        return $this === self::Datapack ? ['.zip'] : ['.jar'];
    }
}
