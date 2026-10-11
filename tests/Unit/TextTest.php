<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function test_turns_markdown_into_plain_text(): void
    {
        $markdown = "# Fabric API\n\n![banner](https://x/banner.png)\n\nEssential **hooks** for [modding](https://fabricmc.net) with `Fabric`.\n\n- one\n* two\n\n---\n\n> quoted";

        $this->assertSame("Fabric API\n\nEssential hooks for modding with Fabric.\n\n• one\n• two\n\nquoted", Text::plain($markdown));
    }

    public function test_strips_html_and_keeps_its_line_breaks(): void
    {
        $html = '<div style="text-align: center"><img src="//x/a.png"><b>Vault</b> is an API.<br>Second line</div><ul><li>Chat</li><li>Economy &amp; more</li></ul><script>alert(1)</script>';

        $this->assertSame("Vault is an API.\nSecond line\n\n• Chat\n• Economy & more", Text::plain($html));
    }

    public function test_cuts_long_text(): void
    {
        $this->assertSame('abc…', Text::plain('abcdef', 3));
    }

    public function test_only_accepts_http_links(): void
    {
        $this->assertSame('https://github.com/a/b', Text::link('https://github.com/a/b'));
        $this->assertNull(Text::link('javascript:alert(1)'));
        $this->assertNull(Text::link('//proxy.spigotmc.org/x'));
        $this->assertNull(Text::link(null));
    }
}
