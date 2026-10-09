<?php

declare(strict_types=1);

namespace MinecraftContent\Sources;

use MinecraftContent\Content\ContentType;
use MinecraftContent\Platform\Platform;

/**
 * A place content is downloaded from. Projects and versions are plain arrays shared by every source:
 *
 * project: source, id, slug, name, author, description, icon, downloads, url
 * version: source, project, id, name, number, type (release|beta|alpha), game_versions, loaders, published,
 *          file {url, name, size, sha1} or null when the author does not allow third-party downloads
 */
interface Source
{
    public function name(): string;

    public function supports(ContentType $type, Platform $platform): bool;

    /** @return array{total: int, projects: list<array<string, mixed>>} */
    public function search(ContentType $type, Platform $platform, string $query, string $sort, int $page): array;

    /** @return list<array<string, mixed>> Newest first. */
    public function versions(ContentType $type, Platform $platform, string $project): array;

    /** @return array<string, mixed> */
    public function project(string $project): array;
}
