<?php

namespace App\Providers;

use App\Support\Shop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // A mistyped SHOP_CURRENCY would print wrong prices and ask Paystack
        // for the wrong money, so the app refuses to start instead.
        Shop::assertConfigured();

        // Placing an order is the one expensive, stateful action a buyer can
        // repeat: 10 submissions a minute per signed-in user, and a looser
        // 20 a minute per IP address so one buyer's tab-mashing cannot lock
        // out the rest of a shared office or mobile carrier.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('checkout:user:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(20)->by('checkout:ip:'.$request->ip()),
        ]);

        // Paystack's webhook is public (it is authenticated by signature, not
        // a session). Bound per IP so a flood of forged requests is cheap to
        // refuse; It is generous because Paystack retries; it is a flood guard, not a gate.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('webhooks:'.$request->ip()));
    }
}
