<?php

namespace App\Services\Discovery;

use App\Models\Order;
use App\Models\Product;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the commerce events behind CommerceMetrics. An event is a name, a keyed hash of who (never a user id or an
 * e-mail address), the day, and at most a product, a store, an order, an amount and a source. Recording never throws
 * into the request that triggered it: a failed insert is logged and dropped.
 */
class Track
{
    public const SEARCH = 'search';

    public const PRODUCT_VIEW = 'product_view';

    public const ADD_TO_CART = 'add_to_cart';

    public const CHECKOUT_STARTED = 'checkout_started';

    public const ORDER_PAID = 'order_paid';

    public const WISHLIST_ADD = 'wishlist_add';

    /** Where a product view came from; anything else is stored as "direct". */
    public const SOURCES = ['search', 'category', 'recommendation', 'collection', 'homepage', 'wishlist', 'direct'];

    public function search(Actor $actor, int $results, bool $zero = false): void
    {
        $this->record(self::SEARCH, $actor, ['value_cents' => $results]);
    }

    public function productView(Actor $actor, Product $product, string $source = 'direct'): void
    {
        $this->record(self::PRODUCT_VIEW, $actor, [
            'product_id' => $product->id,
            'store_id' => $product->store_id,
            'source' => in_array($source, self::SOURCES, true) ? $source : 'direct',
        ]);
    }

    public function addToCart(Actor $actor, Product $product): void
    {
        $this->record(self::ADD_TO_CART, $actor, ['product_id' => $product->id, 'store_id' => $product->store_id]);
    }

    public function checkoutStarted(Actor $actor): void
    {
        $this->record(self::CHECKOUT_STARTED, $actor);
    }

    public function wishlistAdd(Actor $actor, Product $product): void
    {
        $this->record(self::WISHLIST_ADD, $actor, ['product_id' => $product->id, 'store_id' => $product->store_id]);
    }

    /** At most once per order, however many times payment is confirmed. */
    public function orderPaid(Order $order): void
    {
        $actor = $order->user_id !== null ? Actor::forUser((int) $order->user_id) : Actor::forToken('order-'.$order->id);

        $this->record(self::ORDER_PAID, $actor, [
            'order_id' => $order->id,
            'value_cents' => $order->totalCents(),
        ]);
    }

    /**
     * @param  array{product_id?: int|null, store_id?: int|null, order_id?: int|null, value_cents?: int|null, source?: string|null}  $attributes
     */
    public function record(string $name, Actor $actor, array $attributes = []): void
    {
        $hash = $actor->hash();

        if ($hash === null) {
            return;
        }

        try {
            $now = now();

            DB::table('commerce_events')->insertOrIgnore([[
                'name' => $name,
                'actor_hash' => $hash,
                'day' => $now->toDateString(),
                'product_id' => $attributes['product_id'] ?? null,
                'store_id' => $attributes['store_id'] ?? null,
                'order_id' => $attributes['order_id'] ?? null,
                'value_cents' => $attributes['value_cents'] ?? null,
                'source' => $attributes['source'] ?? null,
                'properties' => null,
                'created_at' => $now,
            ]]);
        } catch (Throwable $e) {
            Log::warning('Could not record a commerce event.', ['event' => $name, 'error' => class_basename($e)]);
        }
    }
}
