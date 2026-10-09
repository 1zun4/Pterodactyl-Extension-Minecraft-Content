<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Support\Properties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertiesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function values(): array
    {
        return [
            'plain' => ['A Minecraft Server'],
            'separators' => ['a=b:c#d!e'],
            'leading space' => ['  padded'],
            'unicode' => ['§cRed ♥ Ünïcödé'],
            'emoji' => ['Party 🎉'],
            'backslash' => ['C:\\path\\to'],
            'newline' => ["two\nlines"],
            'empty' => [''],
        ];
    }

    public function test_parses_minecraft_style_files(): void
    {
        $contents = "#Minecraft server properties\n#Thu Oct 09 00:00:00 UTC 2026\nmotd=\\u00A7aHello World\nmax-players = 20\nlevel-name:world\nresource-pack=\nlong=first \\\n    second\n! another comment\n";

        $this->assertSame([
            'motd' => '§aHello World',
            'max-players' => '20',
            'level-name' => 'world',
            'resource-pack' => '',
            'long' => 'first second',
        ], Properties::parse($contents));
    }

    public function test_patch_keeps_comments_order_and_unknown_keys(): void
    {
        $contents = "#comment\nmotd=Old\ncustom-key=keep me\nlong=a \\\n  b\nmax-players=20\n";

        $patched = Properties::patch($contents, ['motd' => 'New', 'long' => 'c', 'pvp' => 'false']);

        $this->assertSame("#comment\nmotd=New\ncustom-key=keep me\nlong=c\nmax-players=20\npvp=false\n", $patched);
    }

    public function test_patch_keeps_windows_line_endings(): void
    {
        $this->assertSame("a=1\r\nb=3\r\n", Properties::patch("a=1\r\nb=2\r\n", ['b' => '3']));
    }

    public function test_patch_creates_a_new_file(): void
    {
        $this->assertSame("motd=Hi\n", Properties::patch('', ['motd' => 'Hi']));
    }

    #[DataProvider('values')]
    public function test_encoded_values_round_trip(string $value): void
    {
        $this->assertSame(['key' => $value], Properties::parse(Properties::patch('', ['key' => $value])));
    }
}
