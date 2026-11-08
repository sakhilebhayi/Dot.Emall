<?php

namespace App\Console\Commands;

use App\Services\Ledger\LedgerService;
use App\Support\Shop;
use Illuminate\Console\Command;

/** Releases seller earnings whose waiting period (7 days after delivery) has passed. Scheduled hourly; safe to run twice. */
class ReleaseLedger extends Command
{
    protected $signature = 'emall:release-ledger';

    protected $description = 'Release held seller earnings whose release date has passed';

    public function handle(LedgerService $ledger): int
    {
        $result = $ledger->releaseDue();

        $this->info("Released {$result['released']} sale(s) worth ".Shop::money($result['net_cents']).". Held back for an open return: {$result['skipped_open_returns']}.");

        return self::SUCCESS;
    }
}
