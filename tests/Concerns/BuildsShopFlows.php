<?php

namespace Tests\Concerns;

use App\Models\Product;
use App\Models\User;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\Checkout\CheckoutDraft;
use Illuminate\Support\Str;

/** Helpers for the W5 page tests: a guest or signed-in cart, a South African address, and the form a checkout posts. */
trait BuildsShopFlows
{
    /** @return array<string, string> */
    protected function address(array $over = []): array
    {
        return array_merge(['recipient_name' => 'Thandi Mokoena', 'line1' => '14 Protea Lane', 'suburb' => 'Parkhurst', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'postal_code' => '2193', 'country' => 'ZA', 'phone' => '+27 82 555 0142'], $over);
    }

    protected function addToCart(?User $user, Product $product, int $qty = 1): void
    {
        app(CartManager::class)->add($user, $product->defaultVariant()->firstOrFail(), $qty);
    }

    /** The form the checkout page posts for a pay press, for a guest unless $buyer's saved address is named. */
    protected function payForm(int $expectedCents, array $over = []): array
    {
        return array_merge([
            'checkout_token' => (string) Str::uuid(),
            'expected_total_cents' => $expectedCents,
            'email' => 'guest.shopper@example.test',
            'phone' => '+27 82 555 0142',
            'address_id' => 'new',
            'address' => $this->address(),
            'terms' => '1',
        ], $over);
    }

    /** What the cart quotes for these lines with the server's own arithmetic, so a test posts the number the page showed. */
    protected function quoteCents(?User $user): int
    {
        $draft = app(CheckoutDraft::class);

        return app(CartQuote::class)->quote(app(CartManager::class)->lines($user), $draft->region($user), $draft->promo(), $draft->methods())->totalCents;
    }
}
