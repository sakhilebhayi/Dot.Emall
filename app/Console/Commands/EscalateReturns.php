<?php

namespace App\Console\Commands;

use App\Services\Returns\ReturnService;
use Illuminate\Console\Command;

/** Sends return requests the seller did not answer in time to the operator queue. Scheduled hourly; safe to run twice. */
class EscalateReturns extends Command
{
    protected $signature = 'emall:escalate-returns';

    protected $description = 'Escalate return requests the seller has not answered by their deadline';

    public function handle(ReturnService $returns): int
    {
        $count = $returns->escalateOverdue();

        $this->info("Escalated {$count} return ".($count === 1 ? 'request' : 'requests').'.');

        return self::SUCCESS;
    }
}
