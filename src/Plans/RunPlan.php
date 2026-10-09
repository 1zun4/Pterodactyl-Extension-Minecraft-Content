<?php

declare(strict_types=1);

namespace MinecraftContent\Plans;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Pterodactyl\Models\Server;
use Throwable;

/**
 * Runs plan steps until the time budget is used up, then queues itself again. Workers stop jobs after 60 seconds.
 */
final class RunPlan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const int BUDGET_SECONDS = 35;

    public int $timeout = 58;

    public int $tries = 1;

    public function __construct(public string $plan)
    {
        $this->onQueue('low');
    }

    public function handle(Plans $plans, Steps $steps): void
    {
        $plan = $plans->get($this->plan);

        if ($plan === null) {
            return;
        }

        $server = Server::query()->find($plan['server']);

        if ($server === null) {
            return;
        }

        $started = microtime(true);

        try {
            while ($plan['position'] < count($plan['steps'])) {
                if ($plans->cancelled($this->plan)) {
                    $plans->finish($plan, $server, 'Cancelled.');

                    return;
                }

                $step = $plan['steps'][$plan['position']];
                $result = $steps->run($server, $step, $plan);

                if ($result->wait !== null) {
                    $plan['steps'][$plan['position']] = $result->state ?? $step;
                    $plans->save($plan);
                    self::dispatch($this->plan)->delay(now()->addSeconds($result->wait));

                    return;
                }

                array_splice($plan['steps'], $plan['position'] + 1, 0, $result->next);
                $plan['position']++;
                $plans->report($plan, $result->message ?? Steps::describe($step));

                if (microtime(true) - $started > self::BUDGET_SECONDS) {
                    $plans->save($plan);
                    self::dispatch($this->plan);

                    return;
                }
            }

            $plans->finish($plan, $server);
        } catch (Throwable $exception) {
            report($exception);
            $plans->finish($plan, $server, $exception->getMessage());
        }
    }
}
