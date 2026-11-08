<?php

namespace App\Services\Operator;

use App\Models\Product;
use App\Models\ScheduledTaskRun;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What is running and what is not, from records. Every line has a state: ok, attention (something a person should look at), or unknown
 * (there is no record, which is NOT the same as fine). Nothing here is a guess dressed as a green tick: a scheduled task that has never
 * recorded a run is "unknown", and so is the queue depth when the queue is not the database.
 */
class PlatformHealth
{
    /** Minutes of slack before a scheduled task counts as late: the scheduler itself only wakes once a minute. */
    private const GRACE_MINUTES = 5;

    /**
     * @return list<array{task: string, expression: string, last_at: ?Carbon, last_status: ?string, state: string, note: string}>
     */
    public function scheduledTasks(): array
    {
        $runs = ScheduledTaskRun::query()->orderByDesc('ran_at')->orderByDesc('id')->limit(2000)->get()->groupBy('task');
        $out = [];

        foreach (app(Schedule::class)->events() as $event) {
            $name = TaskRunRecorder::name((string) ($event->command ?? $event->description ?? ''));
            $last = $runs->get($name)?->first();
            $due = null;

            try {
                $due = Carbon::instance((new CronExpression($event->expression))->getPreviousRunDate(now()->toDateTime()));
            } catch (Throwable) {
            }

            if ($last === null) {
                [$state, $note] = ['unknown', 'No run has been recorded. Either the scheduler has not run since this page was built or its cron entry is missing.'];
            } elseif ($last->status === 'failed') {
                [$state, $note] = ['attention', 'The last run failed'.($last->note ? ': '.$last->note : '.')];
            } elseif ($due !== null && $last->ran_at->lt($due->copy()->subMinutes(self::GRACE_MINUTES))) {
                [$state, $note] = ['attention', 'It should have run at '.$due->format('H:i d M').' and has not been recorded since.'];
            } else {
                [$state, $note] = ['ok', 'Ran '.$last->ran_at->diffForHumans().'.'];
            }

            $out[$name] = ['task' => $name, 'expression' => $this->plain((string) $event->expression), 'last_at' => $last?->ran_at, 'last_status' => $last?->status, 'state' => $state, 'note' => $note];
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * @return list<array{label: string, value: string, state: string, note: string}>
     */
    public function checks(): array
    {
        return [
            $this->failedJobs(),
            $this->queueDepth(),
            $this->paymentEvents(),
            $this->stockHolds(),
            $this->searchIndex(),
        ];
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return $this->line('Failed jobs', 'Unknown', 'unknown', 'The failed jobs table does not exist.');
        }

        $n = (int) DB::table('failed_jobs')->count();
        $last = $n > 0 ? DB::table('failed_jobs')->max('failed_at') : null;

        return $this->line('Failed jobs', (string) $n, $n > 0 ? 'attention' : 'ok', $n > 0 ? 'The latest failed '.Carbon::parse($last)->diffForHumans().'. Retry or clear them from the server (php artisan queue:failed).' : 'None.');
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function queueDepth(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return $this->line('Queue depth', 'Unknown', 'unknown', 'The queue is "'.config('queue.default').'", which this page cannot count. Only the database queue is counted.');
        }

        $waiting = (int) DB::table('jobs')->whereNull('reserved_at')->count();
        $oldest = $waiting > 0 ? (int) DB::table('jobs')->whereNull('reserved_at')->min('available_at') : null;
        $late = $oldest !== null && $oldest < now()->subMinutes(10)->getTimestamp();

        return $this->line('Queue depth', (string) $waiting, $late ? 'attention' : 'ok', $waiting === 0 ? 'Nothing is waiting.' : ($late ? 'The oldest waiting job is more than ten minutes old: is a worker running?' : 'Waiting jobs are recent.'));
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function paymentEvents(): array
    {
        $since = now()->subDays(30);
        $unmatched = (int) DB::table('payment_events')->where('outcome', 'unmatched')->where('created_at', '>=', $since)->count();

        return $this->line('Payment events with no matching payment (30 days)', (string) $unmatched, $unmatched > 0 ? 'attention' : 'ok', $unmatched > 0 ? 'Paystack told us about money we cannot match to an order. They are listed on the payments desk. A webhook with a bad signature is refused before it is recorded, so those are not counted here.' : 'None. A webhook with a bad signature is refused before it is recorded, so those are not counted here.');
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function stockHolds(): array
    {
        $window = (int) (config('paystack.unpaid_window_minutes') ?: 60);
        $cap = max(1, (int) config('emall.checkout.hold_extension_cap', 2));
        $overdue = (int) DB::table('orders')->where('requires_payment', true)->whereIn('payment_status', ['unpaid', 'pending', 'failed'])
            ->where('status', '!=', 'cancelled')->where('created_at', '<=', now()->subMinutes($window * $cap + 15))->count();

        return $this->line('Unpaid orders past their stock hold', (string) $overdue, $overdue > 0 ? 'attention' : 'ok', $overdue > 0 ? 'Their stock should have been released by orders:expire-unpaid. Check that the scheduled task is running.' : 'Every unpaid order is inside its hold or already released.');
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function searchIndex(): array
    {
        if (! Schema::hasTable('search_documents')) {
            return $this->line('Search index', 'Unknown', 'unknown', 'The search index tables do not exist.');
        }

        $listed = Product::query()->browsable()->count();
        $missing = (int) Product::query()->browsable()->whereNotExists(fn ($q) => $q->select(DB::raw('1'))->from('search_documents as d')->whereColumn('d.product_id', 'products.id'))->count();
        $stale = (int) DB::table('products as p')->join('search_documents as d', 'd.product_id', '=', 'p.id')->whereColumn('p.updated_at', '>', 'd.indexed_at')->where('p.is_active', true)->where('p.status', 'approved')->whereNull('p.deleted_at')->count();
        $last = DB::table('search_documents')->max('indexed_at');

        if ($listed === 0) {
            return $this->line('Search index', 'Nothing to index', 'ok', 'No listing is live yet.');
        }

        $behind = $missing + $stale;

        return $this->line('Search index', $behind === 0 ? 'Current' : $behind.' behind', $behind > 0 ? 'attention' : 'ok', ($behind > 0 ? "{$missing} live listings are missing from the index and {$stale} were edited after they were indexed. " : 'Every live listing is indexed. ').'Last indexed '.($last ? Carbon::parse($last)->diffForHumans() : 'never').'. Fix with php artisan emall:search-reindex.');
    }

    /** @return array{label: string, value: string, state: string, note: string} */
    private function line(string $label, string $value, string $state, string $note): array
    {
        return ['label' => $label, 'value' => $value, 'state' => $state, 'note' => $note];
    }

    private function plain(string $expression): string
    {
        return match ($expression) {
            '* * * * *' => 'every minute',
            '*/5 * * * *' => 'every 5 minutes',
            '*/10 * * * *' => 'every 10 minutes',
            '*/15 * * * *' => 'every 15 minutes',
            '0 * * * *' => 'hourly',
            default => $expression,
        };
    }
}
