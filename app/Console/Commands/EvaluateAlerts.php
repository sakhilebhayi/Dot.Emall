<?php

namespace App\Console\Commands;

use App\Services\Discovery\AlertEvaluator;
use Illuminate\Console\Command;

/** Every fifteen minutes: send due back-in-stock and price-drop notices and saved-search digests, once each. */
class EvaluateAlerts extends Command
{
    protected $signature = 'emall:alerts-evaluate';

    protected $description = 'Send due stock and price alerts and saved-search digests';

    public function handle(AlertEvaluator $evaluator): int
    {
        $sent = $evaluator->run();
        $this->info("Back in stock: {$sent['back_in_stock']}. Price drops: {$sent['price_drop']}. Saved-search digests: {$sent['saved_search']}.");

        return self::SUCCESS;
    }
}
