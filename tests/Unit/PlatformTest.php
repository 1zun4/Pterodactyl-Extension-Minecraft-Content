<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;
use PHPUnit\Framework\TestCase;

final class PlatformTest extends TestCase
{
    public function test_plugin_servers_take_plugins_and_world_content(): void
    {
        $paper = new Platform('paper', '1.21.11');

        $this->assertSame(ContentType::Plugin, $paper->codeType());
        $this->assertTrue($paper->supports(ContentType::Datapack));
        $this->assertFalse($paper->supports(ContentType::Mod));
        $this->assertSame(['paper', 'spigot', 'bukkit'], $paper->loaders());
        $this->assertSame('PAPER', $paper->hangarPlatform());
    }

    public function test_proxies_only_take_plugins(): void
    {
        $velocity = new Platform('velocity', null);

        $this->assertTrue($velocity->supports(ContentType::Plugin));
        $this->assertFalse($velocity->supports(ContentType::Modpack));
        $this->assertFalse($velocity->supports(ContentType::Datapack));
    }

    public function test_vanilla_takes_no_code(): void
    {
        $vanilla = new Platform('vanilla', '26.1.2');

        $this->assertNull($vanilla->codeType());
        $this->assertTrue($vanilla->supports(ContentType::Modpack));
        $this->assertSame('mods', (new Platform('quilt', '1.21.1'))->kind());
    }
}
