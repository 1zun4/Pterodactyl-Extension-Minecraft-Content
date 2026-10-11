<?php

declare(strict_types=1);

namespace MinecraftContent\Plans;

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionJobProgress;
use RuntimeException;

/**
 * Long installs run as a list of small steps in the queue. The list lives in the cache next to the job's progress.
 */
final readonly class Plans
{
    public const string EXTENSION = 'minecraft-content';

    private const int TTL = 86400;

    public function __construct(private ExtensionJobProgress $progress) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return string The job id the frontend follows.
     */
    public function start(Server $server, User $user, string $permission, string $label, array $steps): string
    {
        $active = $this->active($server);

        if ($active !== null) {
            throw new RuntimeException('Another install is still running on this server.');
        }

        $job = $this->progress->begin(self::EXTENSION, $user, $server, $permission);

        $this->save([
            'id' => $job->id,
            'server' => $server->id,
            'label' => $label,
            'steps' => $steps,
            'position' => 0,
            'percent' => 0,
            'warnings' => [],
        ]);

        Cache::put($this->activeKey($server), $job->id, self::TTL);
        $this->progress->update(self::EXTENSION, $job->id, 1, $label);
        RunPlan::dispatch($job->id);

        return $job->id;
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $plan = Cache::get("ext:mcc:plan:{$id}");

        return is_array($plan) ? $plan : null;
    }

    /** @param array<string, mixed> $plan */
    public function save(array $plan): void
    {
        Cache::put("ext:mcc:plan:{$plan['id']}", $plan, self::TTL);
    }

    /** The running job stops before its next step. */
    public function cancel(Server $server): void
    {
        $id = $this->active($server);

        if ($id !== null) {
            Cache::put("ext:mcc:cancel:{$id}", true, self::TTL);
        }
    }

    public function cancelled(string $id): bool
    {
        return Cache::pull("ext:mcc:cancel:{$id}") === true;
    }

    public function active(Server $server): ?string
    {
        $id = Cache::get($this->activeKey($server));

        return is_string($id) && $this->get($id) !== null ? $id : null;
    }

    /**
     * Progress only moves forward, even when a step adds more steps.
     *
     * @param  array<string, mixed>  $plan
     */
    public function report(array &$plan, string $message): void
    {
        $total = max(1, count($plan['steps']));
        $plan['percent'] = (int) max($plan['percent'], 1, min(99, floor($plan['position'] / $total * 100)));
        $this->progress->update(self::EXTENSION, $plan['id'], $plan['percent'], mb_substr($message, 0, 1000));
    }

    /** @param array<string, mixed> $plan */
    public function finish(array $plan, Server $server, ?string $error = null): void
    {
        $skipped = array_values(array_unique($plan['skipped'] ?? []));

        if ($skipped !== []) {
            $more = count($skipped) - 8;
            $plan['warnings'][] = 'Not downloaded, add these yourself: '.implode(', ', array_slice($skipped, 0, 8)).($more > 0 ? " and {$more} more" : '').'.';
        }

        $warnings = $plan['warnings'] === [] ? '' : ' '.implode(' ', array_unique($plan['warnings']));
        $message = $error === null ? "{$plan['label']} finished.{$warnings}" : "{$plan['label']} failed: {$error}";

        $this->progress->update(self::EXTENSION, $plan['id'], 100, mb_substr($message, 0, 1000), $error === null ? 'completed' : 'failed');
        Cache::forget("ext:mcc:plan:{$plan['id']}");
        Cache::forget($this->activeKey($server));
    }

    private function activeKey(Server $server): string
    {
        return "ext:mcc:active:{$server->uuid}";
    }
}
