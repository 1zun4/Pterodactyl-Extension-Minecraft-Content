<?php

declare(strict_types=1);

namespace MinecraftContent\Support;

/**
 * Project descriptions arrive as Markdown, HTML or both. The panel shows them as plain text, so nothing from them is rendered.
 */
final class Text
{
    public const int LIMIT = 4000;

    public static function plain(string $text, int $limit = self::LIMIT): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('#<!--.*?-->#s', '', $text);
        $text = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $text);
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = (string) preg_replace('#<li\b[^>]*>#i', "\n• ", $text);
        $text = (string) preg_replace('#</(p|div|h[1-6]|ul|ol|blockquote|tr|center)>#i', "\n", $text);
        $text = strip_tags($text);

        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
        $text = (string) preg_replace('/\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)/', '', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
        $text = (string) preg_replace('/^[ \t]{0,3}#{1,6}[ \t]*/m', '', $text);
        $text = (string) preg_replace('/^[ \t]{0,3}>[ \t]?/m', '', $text);
        $text = (string) preg_replace('/^[ \t]*[-*+][ \t]+/m', '• ', $text);
        $text = (string) preg_replace('/^[ \t]*([-*_][ \t]*){3,}$/m', '', $text);
        $text = (string) preg_replace('/(\*\*|__|~~|`{1,3})/', '', $text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = mb_trim((string) preg_replace('/\n{3,}/', "\n\n", $text));

        return mb_strlen($text) > $limit ? mb_rtrim(mb_substr($text, 0, $limit)).'…' : $text;
    }

    /**
     * @param  array<string, mixed>  $labelled  Label => URL.
     * @return list<array{label: string, url: string}>
     */
    public static function links(array $labelled): array
    {
        $links = [];

        foreach ($labelled as $label => $url) {
            if (($url = self::link($url)) !== null && count($links) < 8) {
                $links[] = ['label' => (string) $label, 'url' => $url];
            }
        }

        return $links;
    }

    /**
     * @param  list<array{0: mixed, 1: mixed}>  $images  [URL, title] pairs.
     * @return list<array{url: string, title: string}>
     */
    public static function gallery(array $images): array
    {
        $gallery = [];

        foreach ($images as [$url, $title]) {
            if (is_string($url) && str_starts_with($url, 'https://') && self::link($url) !== null && count($gallery) < 6) {
                $gallery[] = ['url' => $url, 'title' => is_string($title) ? $title : ''];
            }
        }

        return $gallery;
    }

    /** Only http(s) links, so nothing can run in the panel. */
    public static function link(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
