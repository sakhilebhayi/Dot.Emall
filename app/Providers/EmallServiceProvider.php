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
        // (The throttle runs before route-model binding, so {order} is still the raw id here.)
        RateLimiter::for('guest-order', function (Request $request) {
            $order = $request->route('order');
            $order = is_object($order) ? $order->getKey() : (string) $order;

            return [
                Limit::perMinute(20)->by('guest-order:ip:'.$request->ip()),
                Limit::perMinute(10)->by('guest-order:order:'.$order.':'.$request->ip()),
            ];
        });

        // Asking for a lost order link again: few per address and per person, so it cannot be used to flood someone's inbox.
        RateLimiter::for('guest-link', fn (Request $request) => [
            Limit::perHour(6)->by('guest-link:ip:'.$request->ip()),
            Limit::perHour(3)->by('guest-link:email:'.sha1(mb_strtolower(trim((string) $request->input('email'))))),
        ]);

        // Guest cart writes (a session-only cart is cheap but not free).
        RateLimiter::for('guest-cart', fn (Request $request) => Limit::perMinute(60)->by('guest-cart:'.$request->ip()));
    }
}
