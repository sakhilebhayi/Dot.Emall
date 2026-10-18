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
Schedule::command('orders:expire-unpaid')->everyTenMinutes()->withoutOverlapping();
// One signed aggregate snapshot per company to Dot.Analytics, just after the UTC day turns over.
// taken_at is the start of that UTC day, so a re-run the same day is the same snapshot upstream.
// Does nothing until ANALYTICS_INGEST_URL and ANALYTICS_INGEST_SECRET are set. Needs the host's
// one-minute scheduler cron (* * * * * php artisan schedule:run) and a queue worker.
Schedule::command(PublishMetrics::class)->dailyAt('00:15')->timezone('UTC')->withoutOverlapping();
