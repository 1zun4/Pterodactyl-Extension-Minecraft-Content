<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Support\Murmur2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Murmur2Test extends TestCase
{
    /** @return array<string, array{string, int, int}> */
    public static function hashes(): array
    {
        return [
            'empty' => ['', 1540447798, 1540447798],
            'one byte' => ['a', 626045324, 626045324],
            'two bytes' => ['ab', 1692487918, 1692487918],
            'three bytes' => ['abc', 1621425345, 1621425345],
            'one block' => ['abcd', 3376380438, 3376380438],
            'text' => ['Hello, World!', 613646864, 1961219979],
            'whitespace' => ["line one\nline two\r\n\tindented  spaces", 3564381245, 851151926],
            'binary' => [str_repeat(implode('', array_map(chr(...), range(0, 255))), 3), 3832168824, 137755623],
        ];
    }

    #[DataProvider('hashes')]
    public function test_matches_the_reference_implementation(string $data, int $hash, int $fingerprint): void
    {
        $this->assertSame($hash, Murmur2::hash($data, 1));
        $this->assertSame($fingerprint, Murmur2::curseForge($data));
    }
}
