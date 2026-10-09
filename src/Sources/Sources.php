<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use InvalidArgumentException;
use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;

final readonly class Sources
{
    public function __construct(
        public Modrinth $modrinth,
        public Hangar $hangar,
        public CurseForge $curseForge,
        public Ftb $ftb,
    ) {}

    public function get(string $name): Source
    {
        return match ($name) {
            'modrinth' => $this->modrinth,
            'hangar' => $this->hangar,
            'curseforge' => $this->curseForge,
            'ftb' => $this->ftb,
            default => throw new InvalidArgumentException("Unknown source \"{$name}\"."),
        };
    }

    /** @return list<string> */
    public function available(ContentType $type, Platform $platform): array
    {
        return array_values(array_map(
            static fn (Source $source): string => $source->name(),
            array_filter([$this->modrinth, $this->hangar, $this->curseForge, $this->ftb], static fn (Source $source): bool => $source->supports($type, $platform)),
        ));
    }
}
