<?php

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
