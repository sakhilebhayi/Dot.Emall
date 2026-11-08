<?php

namespace App\Providers;

use App\Services\Operator\TaskRunRecorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The operator desk's wiring that must survive `route:cache` and `config:cache`: the scheduler listeners that feed the platform health
 * page, and the nightly ledger reconciliation. Nothing here is registered from a routes file.
 */
class OperatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ScheduledTaskFinished::class, [TaskRunRecorder::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [TaskRunRecorder::class, 'failed']);

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            // Read-only: it finds disagreements, repairs nothing. Stored, so the operator's ledger page has a history.
            $schedule->command('emall:reconcile --days=3')->dailyAt('02:10')->withoutOverlapping(30);
        });
    }
}
