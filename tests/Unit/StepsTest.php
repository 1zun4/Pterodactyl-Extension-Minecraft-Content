<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Plans\Steps;
use PHPUnit\Framework\TestCase;

final class StepsTest extends TestCase
{
    public function test_safe_paths_stay_inside_the_server(): void
    {
        $this->assertSame('mods/a.jar', Steps::safePath('mods/a.jar'));
        $this->assertSame('config/x.toml', Steps::safePath('./config/x.toml'));
        $this->assertSame('config/sub/y.json', Steps::safePath('config\\sub\\y.json'));
        $this->assertNull(Steps::safePath('../etc/passwd'));
        $this->assertNull(Steps::safePath('mods/../../x'));
        $this->assertNull(Steps::safePath('/absolute'));
        $this->assertNull(Steps::safePath(''));
        $this->assertSame('mods/..a.jar', Steps::safePath('mods/..a.jar'));
    }
}
