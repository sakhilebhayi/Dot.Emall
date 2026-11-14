<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\PersonalAccessToken;
use App\Support\Shop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

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
        // R-50: Livewire updates from a page in the signed-in area carry that page's entitlement check with them.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);

        // A mistyped SHOP_CURRENCY would print wrong prices and ask Paystack
        // for the wrong money, so the app refuses to start instead.
        Shop::assertConfigured();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
