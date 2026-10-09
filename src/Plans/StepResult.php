<?php

declare(strict_types=1);

namespace MinecraftContent\Plans;

final readonly class StepResult
{
    /**
     * @param  list<array<string, mixed>>  $next  Steps to run right after this one.
     * @param  int|null  $wait  Seconds until this step runs again.
     * @param  array<string, mixed>|null  $state  The step as it should look on its next run.
     */
    private function __construct(public array $next = [], public ?int $wait = null, public ?array $state = null, public ?string $message = null) {}

    public static function done(?string $message = null): self
    {
        return new self(message: $message);
    }

    /** @param list<array<string, mixed>> $steps */
    public static function then(array $steps, ?string $message = null): self
    {
        return new self(next: $steps, message: $message);
    }

    /** @param array<string, mixed> $state */
    public static function wait(int $seconds, array $state): self
    {
        return new self(wait: $seconds, state: $state);
    }
}
