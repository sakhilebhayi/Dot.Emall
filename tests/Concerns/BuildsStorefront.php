<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalogue\ProductVariants;

/** Fixtures for the storefront page tests (product page, reviews, questions, stores, wishlist). */
trait BuildsStorefront
{
    /**
     * A shirt in sizes S, M, L and colours Red, Blue. S-Red has 3, M-Red 5, L-Red none, S-Blue 7; M-Blue and L-Blue are not made.
     *
     * @return array{0: User, 1: Product}
     */
    protected function shirt(array $attributes = []): array
    {
        $seller = $this->makeSeller(['name' => 'Karoo Shirt House']);
        $product = $this->makeListing($seller, array_merge(['name' => 'Cotton Overshirt Distinct', 'price' => 100, 'stock' => 0], $attributes));
        $variants = app(ProductVariants::class);
        $variants->defineOptions($product, ['Size' => ['S', 'M', 'L'], 'Colour' => ['Red', 'Blue']]);
        $variants->saveVariant($product, ['Size' => 'S', 'Colour' => 'Red'], ['price' => 120, 'stock' => 3]);
        $variants->saveVariant($product, ['Size' => 'M', 'Colour' => 'Red'], ['price' => 120, 'stock' => 5]);
        $variants->saveVariant($product, ['Size' => 'L', 'Colour' => 'Red'], ['price' => 140, 'stock' => 0]);
        $variants->saveVariant($product, ['Size' => 'S', 'Colour' => 'Blue'], ['price' => 130, 'stock' => 7]);

        return [$seller, $product->fresh()];
    }

    /** A buyer whose order for the product has been delivered (so they may review it and the review is verified). */
    protected function deliveredBuyerOf(Product $product): User
    {
        $buyer = $this->makeBuyer();
        $order = $this->placeOrder($buyer, [[$product, 1]]);
        $order->items->first()->update(['status' => OrderStatus::Delivered]);

        return $buyer;
    }

    protected function operator(): User
    {
        return User::factory()->withPersonalTeam()->create(['is_platform_operator' => true]);
    }
}
