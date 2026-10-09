<?php

declare(strict_types=1);

namespace MinecraftContent\Support;

/**
 * The file fingerprint CurseForge uses: MurmurHash2 with seed 1 over the file without whitespace bytes.
 */
final class Murmur2
{
    private const int M = 0x5BD1E995;

    public static function curseForge(string $data): int
    {
        return self::hash(str_replace(["\t", "\n", "\r", ' '], '', $data), 1);
    }

    public static function hash(string $data, int $seed): int
    {
        $length = mb_strlen($data, '8bit');
        $hash = ($seed ^ $length) & 0xFFFFFFFF;
        $blocks = intdiv($length, 4);
        $words = $blocks > 0 ? unpack('V*', mb_substr($data, 0, $blocks * 4, '8bit')) : [];

        foreach ($words as $k) {
            $k = self::multiply($k, self::M);
            $k ^= $k >> 24;
            $k = self::multiply($k, self::M);
            $hash = self::multiply($hash, self::M) ^ $k;
        }

        $tail = mb_substr($data, $blocks * 4, null, '8bit');

        switch (mb_strlen($tail, '8bit')) {
            case 3:
                $hash ^= ord($tail[2]) << 16;
                // no break
            case 2:
                $hash ^= ord($tail[1]) << 8;
                // no break
            case 1:
                $hash ^= ord($tail[0]);
                $hash = self::multiply($hash, self::M);
        }

        $hash ^= $hash >> 13;
        $hash = self::multiply($hash, self::M);

        return ($hash ^ ($hash >> 15)) & 0xFFFFFFFF;
    }

    private static function multiply(int $a, int $b): int
    {
        return ((($a & 0xFFFF) * $b) + ((((($a >> 16) & 0xFFFF) * $b) & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
