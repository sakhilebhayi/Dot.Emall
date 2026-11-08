<?php

namespace App\Console\Commands;

use App\Services\Ledger\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Orders vs payments vs refunds vs the seller ledger. Prints a report and FAILS (exit 1, critical log) on any mismatch, so a
 * scheduler wrapper or a person running it before a release cannot miss a disagreement. It reads only; it repairs nothing.
 */
class Reconcile extends Command
{
    protected $signature = 'emall:reconcile {--days= : Only orders paid in the last N days}';

    protected $description = 'Check orders, payments, refunds and the seller ledger agree; exits non-zero on any mismatch';

    public function handle(Reconciler $reconciler): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $result = $reconciler->run($days);

        $this->info("Checked {$result['checked']} paid orders.");

        if ($result['mismatches'] === []) {
            $this->info('Everything agrees.');

            return self::SUCCESS;
        }

        $this->error(count($result['mismatches']).' mismatch(es):');
        $this->table(['Order', 'Check', 'Expected', 'Actual', 'Detail'], array_map(
            fn (array $m) => [$m['order_id'] ?? '-', $m['check'], $m['expected'], $m['actual'], $m['detail']],
            $result['mismatches'],
        ));

        Log::critical('emall:reconcile found mismatches.', ['count' => count($result['mismatches']), 'checks' => array_values(array_unique(array_column($result['mismatches'], 'check')))]);

        return self::FAILURE;
    }
}
