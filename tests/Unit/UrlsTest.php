<?php

declare(strict_types=1);

namespace MinecraftContent\Tests\Unit;

use MinecraftContent\Support\Urls;
use PHPUnit\Framework\TestCase;

final class UrlsTest extends TestCase
{
    public function test_curseforge_urls_skip_the_redirect_and_encode_plus_signs(): void
    {
        $this->assertSame(
            'https://mediafilez.forgecdn.net/files/4483/509/CraftPresence-2.0.0-rc.2%2B1.19.2.jar',
            Urls::direct('https://edge.forgecdn.net/files/4483/509/CraftPresence-2.0.0-rc.2+1.19.2.jar'),
        );
    }

    public function test_already_encoded_urls_stay_the_same(): void
    {
        $url = 'https://cdn.modrinth.com/data/AANobbMI/versions/abc/sodium-fabric-0.6.0%2Bmc1.21.jar?x=1';

        $this->assertSame($url, Urls::direct($url));
        $this->assertSame('https://example.com/a%20b.jar', Urls::direct('https://example.com/a b.jar'));
    }
}
