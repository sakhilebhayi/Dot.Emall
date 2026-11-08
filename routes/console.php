<?php

use App\Console\Commands\PublishMetrics;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Give up orders that asked for payment and never got it (see
// App\Console\Commands\ExpireUnpaidOrders). Needs the usual
// `* * * * * php artisan schedule:run` cron entry.
// withoutOverlapping() keeps its lock for 24 hours by default; on a shared host a killed run would then block the
// stock-releasing sweep for a day. The lock outlives one run, not a day.
Schedule::command('orders:expire-unpaid')->everyTenMinutes()->withoutOverlapping(15);
// One signed aggregate snapshot per company to Dot.Analytics, just after the UTC day turns over.
// taken_at is the start of that UTC day, so a re-run the same day is the same snapshot upstream.
// Does nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set. Needs the host's
// one-minute scheduler cron (* * * * * php artisan schedule:run) and a queue worker.
Schedule::command(PublishMetrics::class)->dailyAt('00:15')->timezone('UTC')->withoutOverlapping(30);

// Seller earnings: release what has waited out the post-delivery period, and send unanswered return requests to the operator
// queue. Both are idempotent and take a short overlap lock so a killed run cannot block the next for a day.
Schedule::command('emall:release-ledger')->hourly()->withoutOverlapping(30);
Schedule::command('emall:escalate-returns')->hourly()->withoutOverlapping(30);
