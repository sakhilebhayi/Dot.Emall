<?php

namespace App\Providers;

use App\Services\Cart\CartManager;
use App\Services\Paystack\FakePaystackClient;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Support\ServiceProvider;

/**
 * Cart, checkout and order wiring that must not live in a routes file (a binding registered there breaks `route:cache`).
 *
 *  - `emall.cart_count`: the number the header shows, for a guest (session cart) and a signed-in person alike.
 *  - The TESTING-ONLY stand-in for Paystack's hosted page. It replaces the client only when APP_ENV is local or testing AND
 *    EMALL_E2E_FAKE_PAYSTACK is on; nowhere else, and a test proves the production path never gets it.
 */
class CheckoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (self::fakeGatewayAllowed()) {
            $this->app->singleton(PaystackClient::class, fn ($app) => new FakePaystackClient($app->make(PaystackConfig::class)));
        }
    }

    public function boot(): void
    {
        // Shell::cartCount() calls the bound value, so the binding returns a closure that does the counting.
        $this->app->bind('emall.cart_count', fn ($app) => fn () => $app->make(CartManager::class)->count(auth()->user()));
    }

    /** Local development and the test suite only, and only when the flag is set. Production can never turn it on. */
    public static function fakeGatewayAllowed(): bool
    {
        return app()->environment('local', 'testing') && (bool) config('emall.e2e_fake_paystack');
    }
}
