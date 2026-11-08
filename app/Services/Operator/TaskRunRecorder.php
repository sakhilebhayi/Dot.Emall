<?php

namespace App\Services\Operator;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one row per run of a scheduled command, so the platform health page can say "last ran at ..." from a record and not a guess.
 * Registered as an event listener in OperatorServiceProvider (a provider, never a route file). Never throws: a failure to record must
 * not fail the task it is recording.
 */
class TaskRunRecorder
{
    public function finished(ScheduledTaskFinished $event): void
    {
        $this->write($event->task->command ?? $event->task->description ?? 'scheduled task', $event->task->exitCode ?? 0, (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->write($event->task->command ?? $event->task->description ?? 'scheduled task', 1, null, Str::limit($event->exception->getMessage(), 240, ''));
    }

    public static function name(string $command): string
    {
        // "'/usr/bin/php' 'artisan' emall:reconcile --days=3" -> "emall:reconcile"
        if (preg_match("/artisan'?\s+([a-z][a-z0-9:_\-]*)/i", $command, $m)) {
            return $m[1];
        }

        return Str::limit($command, 120, '');
    }

    private function write(string $command, int $exit, ?int $ms, ?string $note = null): void
    {
        try {
            ScheduledTaskRun::query()->create([
                'task' => self::name($command),
                'status' => $exit === 0 ? 'success' : 'failed',
                'ran_at' => now(),
                'runtime_ms' => $ms,
                'note' => $note,
            ]);

            if (random_int(1, 50) === 1) {
                ScheduledTaskRun::query()->where('ran_at', '<', now()->subDays(14))->delete();
            }
        } catch (Throwable) {
        }
    }
}
