<?php

namespace Tests\Concerns;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\CheckoutService;

/**
 * Shared fixtures for marketplace tests. Everything goes through the real
 * models and the real CheckoutService, so a line built here carries the
 * same snapshot and pricing columns as one built by a buyer.
 *
 * Assertions against whole pages must use long distinctive strings
 * (product names below), never short numbers: random factory data collides
 * with them and makes the test flaky.
 */
trait BuildsMarketplace
{
    protected function makeSeller(array $store = []): User
    {
        $seller = User::factory()->withPersonalTeam()->create();
        Store::factory()->create(array_merge(['owner_id' => $seller->id], $store));

        return $seller->fresh();
    }

    protected function makeBuyer(): User
    {
        return User::factory()->withPersonalTeam()->create();
    }

    protected function makeListing(User $seller, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge(['store_id' => $seller->store->id], $attributes));
    }

    /**
     * Puts the given [product, quantity] pairs in the buyer's cart and checks out.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    protected function placeOrder(User $buyer, array $lines, array $input = []): Order
    {
        foreach ($lines as [$product, $quantity]) {
            CartItem::withoutGlobalScopes()->create([
                'user_id' => $buyer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return app(CheckoutService::class)->place($buyer, array_merge([
            'shipping_name' => 'Thandiwe Mokoena',
            'shipping_address' => '14 Protea Lane, Parkhurst, Johannesburg, 2193',
            'shipping_phone' => '+27 82 555 0142',
        ], $input))->load('items');
    }
}
