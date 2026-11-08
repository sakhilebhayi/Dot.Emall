<?php

namespace App\Console\Commands;

use App\Services\Discovery\RecentlyViewed;
use App\Services\Discovery\SearchLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Retention: search log and product views 90 days, commerce events 400 days (all configurable). */
class PruneDiscoveryData extends Command
{
    protected $signature = 'emall:discovery-prune';

    protected $description = 'Delete search history, product views and commerce events older than their retention';

    public function handle(SearchLog $log, RecentlyViewed $viewed): int
    {
        $searches = $log->prune();
        $views = $viewed->prune();
        $events = DB::table('commerce_events')->where('created_at', '<', now()->subDays((int) config('discovery.retention.commerce_events_days', 400)))->delete();

        $this->info("Deleted {$searches} searches, {$views} product views, {$events} events.");

        return self::SUCCESS;
    }
}
