<?php

declare(strict_types=1);

namespace MinecraftContent\Support;

/**
 * Reads and patches Java .properties files without touching comments, order or unknown keys.
 */
final class Properties
{
    /** @return array<string, string> */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (self::logicalLines($contents) as $line) {
            $entry = self::entry($line['text']);

            if ($entry !== null) {
                $values[$entry[0]] = $entry[1];
            }
        }

        return $values;
    }

    /** @param array<string, string> $changes */
    public static function patch(string $contents, array $changes): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $lines = $contents === '' ? [] : preg_split('/\r\n|\n|\r/', $contents);

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $pending = $changes;
        $output = [];
        $logical = array_column(self::logicalLines($contents), null, 'start');
        $skipUntil = -1;

        foreach ($lines as $index => $line) {
            if ($index <= $skipUntil) {
                continue;
            }

            $current = $logical[$index] ?? null;
            $entry = $current === null ? null : self::entry($current['text']);

            if ($entry !== null && array_key_exists($entry[0], $pending)) {
                $output[] = self::encodeKey($entry[0]).'='.self::encodeValue($pending[$entry[0]]);
                unset($pending[$entry[0]]);
                $skipUntil = $current['end'];

                continue;
            }

            $output[] = $line;
        }

        foreach ($pending as $key => $value) {
            $output[] = self::encodeKey($key).'='.self::encodeValue($value);
        }

        return implode($eol, $output).$eol;
    }

    public static function encodeValue(string $value): string
    {
        $encoded = '';
        $length = mb_strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1);
            $encoded .= match ($char) {
                '\\' => '\\\\',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                "\f" => '\\f',
                '=', ':', '#', '!' => '\\'.$char,
                ' ' => $i === 0 ? '\\ ' : ' ',
                default => self::escapeUnicode($char),
            };
        }

        return $encoded;
    }

    private static function encodeKey(string $key): string
    {
        return str_replace(' ', '\\ ', self::encodeValue($key));
    }

    private static function escapeUnicode(string $char): string
    {
        $code = mb_ord($char);

        if ($code >= 0x20 && $code <= 0x7E) {
            return $char;
        }

        $units = mb_convert_encoding($char, 'UTF-16BE', 'UTF-8');

        return implode('', array_map(
            static fn (string $unit): string => sprintf('\\u%04X', unpack('n', $unit)[1]),
            mb_str_split($units, 2, '8bit'),
        ));
    }

    /** @return array{0: string, 1: string}|null */
    private static function entry(string $text): ?array
    {
        $text = mb_ltrim($text, " \t\f");

        if ($text === '' || $text[0] === '#' || $text[0] === '!') {
            return null;
        }

        $length = mb_strlen($text, '8bit');
        $position = 0;

        while ($position < $length) {
            $char = $text[$position];

            if ($char === '\\') {
                $position += 2;

                continue;
            }

            if ($char === '=' || $char === ':' || $char === ' ' || $char === "\t" || $char === "\f") {
                break;
            }

            $position++;
        }

        $key = mb_substr($text, 0, $position, '8bit');
        $rest = mb_ltrim(mb_substr($text, $position, null, '8bit'), " \t\f");

        if ($rest !== '' && ($rest[0] === '=' || $rest[0] === ':')) {
            $rest = mb_ltrim(mb_substr($rest, 1, null, '8bit'), " \t\f");
        }

        return [self::decode($key), self::decode($rest)];
    }

    private static function decode(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\\\\(u([0-9a-fA-F]{4})|.)/s',
            static fn (array $match): string => match (true) {
                isset($match[2]) && $match[2] !== '' => mb_convert_encoding(pack('n', hexdec($match[2])), 'UTF-8', 'UTF-16BE'),
                $match[1] === 'n' => "\n",
                $match[1] === 'r' => "\r",
                $match[1] === 't' => "\t",
                $match[1] === 'f' => "\f",
                default => $match[1],
            },
            self::joinSurrogates($raw),
        );
    }

    private static function joinSurrogates(string $raw): string
    {
        return (string) preg_replace_callback(
            '/\\\\u(d[89ab][0-9a-f]{2})\\\\u(d[c-f][0-9a-f]{2})/i',
            static fn (array $match): string => mb_convert_encoding(pack('nn', hexdec($match[1]), hexdec($match[2])), 'UTF-8', 'UTF-16BE'),
            $raw,
        );
    }

    /** @return list<array{start: int, end: int, text: string}> */
    private static function logicalLines(string $contents): array
    {
        $physical = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = [];
        $count = count($physical);

        for ($index = 0; $index < $count; $index++) {
            $start = $index;
            $text = mb_ltrim($physical[$index], " \t\f");
            $isComment = $text !== '' && ($text[0] === '#' || $text[0] === '!');

            while (! $isComment && self::continues($text) && $index + 1 < $count) {
                $text = mb_substr($text, 0, -1, '8bit').mb_ltrim($physical[++$index], " \t\f");
            }

            $lines[] = ['start' => $start, 'end' => $index, 'text' => $text];
        }

        return $lines;
    }

    private static function continues(string $text): bool
    {
        preg_match('/(\\\\*)$/', $text, $match);

        return mb_strlen($match[1], '8bit') % 2 === 1;
    }
}
