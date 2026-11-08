<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalogue\PriceHistory;
use Illuminate\Validation\ValidationException;

/**
 * Back-in-stock and price-drop alerts. Subscribing is the consent: an alert is one person asking to be told one
 * thing about one product, it sends at most one notice per event (see AlertEvaluator) and is easy to cancel.
 *
 * A price-drop alert remembers the price the person SAW when they subscribed (baseline_price, the live price at that
 * moment), and fires only when the live price is below that. Where the commerce workstream's price history exists
 * the notice also quotes the lowest recorded price of the last 30 days; where it does not, nothing is invented.
 */
class ProductAlerts
{
    public function __construct(private readonly PriceHistory $history) {}

    /**
     * @throws ValidationException
     */
    public function subscribe(User $user, Product $product, string $kind, ?float $targetPrice = null, ?int $variantId = null): ProductAlert
    {
        if (! in_array($kind, [ProductAlert::BACK_IN_STOCK, ProductAlert::PRICE_DROP], true)) {
            throw ValidationException::withMessages(['kind' => 'Choose back in stock or price drop.']);
        }

        if (! Product::query()->browsable()->whereKey($product->id)->exists()) {
            throw ValidationException::withMessages(['product' => 'This product is not available.']);
        }

        if ($kind === ProductAlert::BACK_IN_STOCK && $product->stock > 0) {
            throw ValidationException::withMessages(['kind' => 'This product is in stock now. You can buy it, or set a price alert instead.']);
        }

        if ($kind === ProductAlert::PRICE_DROP && $targetPrice !== null && ($targetPrice <= 0 || $targetPrice >= (float) $product->price)) {
            throw ValidationException::withMessages(['target_price' => 'Choose a target price below the current price of '.number_format((float) $product->price, 2).'.']);
        }

        $key = $variantId ?? 0;
        $existing = ProductAlert::query()->where('user_id', $user->id)->where('product_id', $product->id)->where('variant_key', $key)->where('kind', $kind)->first();

        if ($existing === null && ProductAlert::query()->where('user_id', $user->id)->where('active', true)->count() >= (int) config('discovery.wishlist.max_alerts', 100)) {
            throw ValidationException::withMessages(['kind' => 'You have the most alerts allowed. Cancel one first.']);
        }

        $attributes = [
            'variant_id' => $variantId,
            'baseline_price' => $product->price,
            'target_price' => $kind === ProductAlert::PRICE_DROP ? $targetPrice : null,
            'last_notified_price' => null,
            'active' => true,
            'triggered_at' => null,
        ];

        if ($existing) {
            $existing->forceFill($attributes)->save();

            return $existing;
        }

        return ProductAlert::query()->create(array_merge($attributes, [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'variant_key' => $key,
            'kind' => $kind,
        ]));
    }

    public function cancel(ProductAlert $alert): void
    {
        $alert->update(['active' => false]);
    }

    /** The lowest price recorded in the last 30 days (the alert's variant when it has one), in rand. */
    public function lowestRecentPrice(Product $product, ?int $variantId = null): ?float
    {
        $subject = $variantId !== null ? ProductVariant::query()->where('product_id', $product->id)->find($variantId) : $product;

        $cents = $this->history->lowestSince($subject ?? $product, now()->subDays((int) config('emall.reference_price.window_days', 30)));

        return $cents === null ? null : $cents / 100;
    }
}
