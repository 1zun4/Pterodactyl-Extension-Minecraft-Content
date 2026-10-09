<?php

declare(strict_types=1);

namespace MinecraftContent\Support;

final class Urls
{
    /** Wings does not follow redirects; CurseForge's edge host always redirects to mediafilez. */
    public static function direct(string $url): string
    {
        return (string) preg_replace('#^https://edge\.forgecdn\.net/#', 'https://mediafilez.forgecdn.net/', $url);
    }
}
