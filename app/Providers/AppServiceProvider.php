<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Placing an order is the one expensive, stateful action a buyer can
        // repeat: 10 submissions a minute per signed-in user, and a looser
        // 20 a minute per IP address so one buyer's tab-mashing cannot lock
        // out the rest of a shared office or mobile carrier.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('checkout:user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(20)->by('checkout:ip:'.$request->ip()),
        ]);
    }
}
