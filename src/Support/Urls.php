<?php

declare(strict_types=1);

namespace MinecraftContent\Support;

final class Urls
{
    /**
     * Wings does not follow redirects; CurseForge's edge host always redirects to mediafilez.
     * CDNs read a raw "+" in a file name as a space, so path segments are encoded strictly.
     */
    public static function direct(string $url): string
    {
        $url = (string) preg_replace('#^https://edge\.forgecdn\.net/#', 'https://mediafilez.forgecdn.net/', $url);
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $path = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $parts['path'] ?? '')));

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ":{$parts['port']}" : '').$path.(isset($parts['query']) ? "?{$parts['query']}" : '');
    }
}
