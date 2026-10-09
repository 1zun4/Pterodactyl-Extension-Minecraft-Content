<?php

declare(strict_types=1);

namespace MinecraftContent\Platform;

use MinecraftContent\Content\ContentType;

/**
 * The server software and Minecraft version content is installed for.
 */
final readonly class Platform
{
    public const array SOFTWARE = [
        'vanilla' => ['kind' => 'vanilla', 'loaders' => [], 'hangar' => null, 'mcjars' => 'VANILLA'],
        'paper' => ['kind' => 'plugins', 'loaders' => ['paper', 'spigot', 'bukkit'], 'hangar' => 'PAPER', 'mcjars' => 'PAPER'],
        'purpur' => ['kind' => 'plugins', 'loaders' => ['purpur', 'paper', 'spigot', 'bukkit'], 'hangar' => 'PAPER', 'mcjars' => 'PURPUR'],
        'folia' => ['kind' => 'plugins', 'loaders' => ['folia'], 'hangar' => 'PAPER', 'mcjars' => 'FOLIA'],
        'spigot' => ['kind' => 'plugins', 'loaders' => ['spigot', 'bukkit'], 'hangar' => null, 'mcjars' => 'SPIGOT'],
        'fabric' => ['kind' => 'mods', 'loaders' => ['fabric'], 'hangar' => null, 'mcjars' => 'FABRIC'],
        'quilt' => ['kind' => 'mods', 'loaders' => ['quilt', 'fabric'], 'hangar' => null, 'mcjars' => 'QUILT'],
        'forge' => ['kind' => 'mods', 'loaders' => ['forge'], 'hangar' => null, 'mcjars' => 'FORGE'],
        'neoforge' => ['kind' => 'mods', 'loaders' => ['neoforge'], 'hangar' => null, 'mcjars' => 'NEOFORGE'],
        'velocity' => ['kind' => 'proxy', 'loaders' => ['velocity'], 'hangar' => 'VELOCITY', 'mcjars' => 'VELOCITY'],
        'bungeecord' => ['kind' => 'proxy', 'loaders' => ['bungeecord', 'waterfall'], 'hangar' => 'WATERFALL', 'mcjars' => 'BUNGEECORD'],
    ];

    public function __construct(
        public string $software,
        public ?string $minecraft,
        public bool $detected = true,
    ) {}

    public function kind(): string
    {
        return self::SOFTWARE[$this->software]['kind'];
    }

    /** @return list<string> Modrinth loader names. */
    public function loaders(): array
    {
        return self::SOFTWARE[$this->software]['loaders'];
    }

    public function hangarPlatform(): ?string
    {
        return self::SOFTWARE[$this->software]['hangar'];
    }

    /** The content type plugins or mods install as, or null on vanilla servers. */
    public function codeType(): ?ContentType
    {
        return match ($this->kind()) {
            'plugins', 'proxy' => ContentType::Plugin,
            'mods' => ContentType::Mod,
            default => null,
        };
    }

    public function supports(ContentType $type): bool
    {
        return match ($type) {
            ContentType::Plugin => in_array($this->kind(), ['plugins', 'proxy'], true),
            ContentType::Mod => $this->kind() === 'mods',
            ContentType::Datapack, ContentType::ResourcePack, ContentType::Modpack => $this->kind() !== 'proxy',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'software' => $this->software,
            'minecraft' => $this->minecraft,
            'kind' => $this->kind(),
            'loaders' => $this->loaders(),
            'detected' => $this->detected,
        ];
    }
}
