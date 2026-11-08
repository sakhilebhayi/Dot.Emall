<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Dot.Emall's commerce wiring that must NOT live in a routes file (a throttle or alias registered there breaks
 * `route:cache`, the outage the route-cache lesson is about): rate limiters for guest checkout and order links.
 */
class EmallServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Following a guest order link: per IP and, so one address cannot hammer a single order id, per order too.
        RateLimiter::for('guest-order', fn (Request $request) => [
            Limit::perMinute(20)->by('guest-order:ip:'.$request->ip()),
            Limit::perMinute(10)->by('guest-order:order:'.$request->route('order')?->getKey().':'.$request->ip()),
        ]);

        // Guest cart writes (a session-only cart is cheap but not free).
        RateLimiter::for('guest-cart', fn (Request $request) => Limit::perMinute(60)->by('guest-cart:'.$request->ip()));
    }
}
