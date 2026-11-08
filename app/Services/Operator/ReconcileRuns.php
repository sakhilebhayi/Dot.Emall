<?php

namespace App\Services\Operator;

use App\Models\ReconcileRun;
use App\Models\User;
use App\Services\Ledger\Reconciler;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs the ledger reconciliation (read-only, see Reconciler) and keeps what it found, so the operator sees a history and the latest
 * mismatches on a page instead of a line in a log. The scheduled command, a person running it in a terminal and the operator's "run
 * it now" button all land here.
 */
class ReconcileRuns
{
    private const KEEP_MISMATCHES = 200;

    public function __construct(private readonly Reconciler $reconciler) {}

    public function run(string $trigger = 'command', ?int $days = null, ?User $by = null): ReconcileRun
    {
        $result = $this->reconciler->run($days);

        $run = ReconcileRun::query()->create([
            'ran_at' => now(),
            'days' => $days,
            'checked' => $result['checked'],
            'mismatch_count' => count($result['mismatches']),
            'mismatches' => array_slice($result['mismatches'], 0, self::KEEP_MISMATCHES),
            'trigger' => $trigger,
            'run_by' => $by?->id,
        ]);

        try {
            // Keep ninety days of history: one row a day is a few kilobytes.
            ReconcileRun::query()->where('ran_at', '<', now()->subDays(90))->delete();
        } catch (Throwable) {
        }

        return $run;
    }

    public function latest(): ?ReconcileRun
    {
        return ReconcileRun::query()->orderByDesc('ran_at')->orderByDesc('id')->first();
    }

    /** @return Collection<int, ReconcileRun> */
    public function history(int $limit = 15): Collection
    {
        return ReconcileRun::query()->orderByDesc('ran_at')->orderByDesc('id')->limit($limit)->get();
    }
}
