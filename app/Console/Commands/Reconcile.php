<?php

namespace App\Console\Commands;

use App\Services\Operator\ReconcileRuns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Orders vs payments vs refunds vs the seller ledger. Prints a report and FAILS (exit 1, critical log) on any mismatch, so a
 * scheduler wrapper or a person running it before a release cannot miss a disagreement. It reads only; it repairs nothing.
 * Each run is stored (reconcile_runs) so the operator's ledger page can show the history and the latest mismatches.
 */
class Reconcile extends Command
{
    protected $signature = 'emall:reconcile {--days= : Only orders paid in the last N days}';

    protected $description = 'Check orders, payments, refunds and the seller ledger agree; exits non-zero on any mismatch';

    public function handle(ReconcileRuns $runs): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $run = $runs->run('command', $days);
        $result = ['checked' => $run->checked, 'mismatches' => $run->mismatches ?? []];

        $this->info("Checked {$result['checked']} paid orders.");

        if ($result['mismatches'] === []) {
            $this->info('Everything agrees.');

            return self::SUCCESS;
        }

        $this->error($run->mismatch_count.' mismatch(es)'.($run->mismatch_count > count($result['mismatches']) ? ' (the first '.count($result['mismatches']).' are listed)' : '').':');
        $this->table(['Order', 'Check', 'Expected', 'Actual', 'Detail'], array_map(
            fn (array $m) => [$m['order_id'] ?? '-', $m['check'], $m['expected'], $m['actual'], $m['detail']],
            $result['mismatches'],
        ));

        Log::critical('emall:reconcile found mismatches.', ['count' => $run->mismatch_count, 'checks' => array_values(array_unique(array_column($result['mismatches'], 'check')))]);

        return self::FAILURE;
    }
}
