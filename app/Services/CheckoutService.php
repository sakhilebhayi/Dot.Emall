<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a buyer's cart into an order. There is no payment step: the order
 * that comes out is "pending, unpaid" and stays that way until a payment
 * provider is chosen and integrated.
 *
 * Everything that must hold together happens in one transaction: product
 * rows are locked (in id order, so concurrent checkouts that share products
 * cannot deadlock), stock is re-checked and decremented with a
 * WHERE-guarded update (the only protection under SQLite, where row locks
 * are no-ops), the promo row is locked and its use counted, and each line
 * is written with a snapshot of what was bought. OrderPlaced is dispatched
 * only after the commit.
 */
class CheckoutService
{
    public function __construct(private readonly OrderPricing $pricing) {}

    /**
     * @param  array{shipping_name?: ?string, shipping_address: string, shipping_phone?: ?string, promo_code?: ?string}  $input
     *
     * @throws CheckoutException
     */
    public function place(User $buyer, array $input): Order
    {
        $cartItemIds = CartItem::query()->where('user_id', $buyer->id)->pluck('id');

        if ($cartItemIds->isEmpty()) {
            throw new CheckoutException('Your cart is empty.');
        }

        $order = DB::transaction(function () use ($buyer, $input, $cartItemIds) {
            $items = CartItem::query()
                ->where('user_id', $buyer->id)
                ->whereIn('id', $cartItemIds)
                ->get();

            $lockedProducts = Product::query()
                ->with('store')
                ->whereIn('id', $items->pluck('product_id')->sort()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $product = $lockedProducts->get($item->product_id);

                if (! $product || ! $product->is_active || $product->status !== 'approved' || ! $product->store?->is_active) {
                    $name = $product?->name ?? 'An item in your cart';
                    throw new CheckoutException("\"{$name}\" is no longer available. Remove it from your cart to continue.");
                }

                if ($item->quantity > $product->stock) {
                    throw new CheckoutException($this->shortStockMessage($product, $item->quantity));
                }
            }

            $promo = $this->resolvePromo($input['promo_code'] ?? null, $items, $lockedProducts);

            $order = Order::create([
                'user_id' => $buyer->id,
                'status' => OrderStatus::Pending,
                'subtotal' => 0,
                'total' => 0,
                'discount_amount' => 0,
                'promo_code' => $promo?->code,
                'promo_code_id' => $promo?->id,
                'promo_store_id' => $promo?->store_id,
                'promo_discount_type' => $promo?->discount_type,
                'promo_discount_value' => $promo?->discount_value,
                'shipping_name' => $input['shipping_name'] ?? null ?: $buyer->name,
                'shipping_address' => $input['shipping_address'],
                'shipping_phone' => $input['shipping_phone'] ?? null,
            ]);

            foreach ($items as $item) {
                $product = $lockedProducts[$item->product_id];

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'store_id' => $product->store_id,
                    'store_name' => $product->store->name,
                    'seller_id' => $product->store->owner_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $product->price,
                    'total' => Money::fromCents(Money::toCents($product->price) * $item->quantity),
                    'status' => OrderStatus::Pending,
                ]);

                // Re-checks stock at write time: even without a real row
                // lock (SQLite) this can never drive stock below zero.
                $updated = Product::query()
                    ->where('id', $product->id)
                    ->where('stock', '>=', $item->quantity)
                    ->decrement('stock', $item->quantity);

                if ($updated === 0) {
                    throw new CheckoutException($this->shortStockMessage($product, $item->quantity));
                }
            }

            $this->pricing->apply($order);

            if ($promo !== null) {
                $promo->increment('used_count');
            }

            CartItem::query()->where('user_id', $buyer->id)->whereIn('id', $cartItemIds)->delete();

            return $order;
        });

        // After the commit, not inside it: a listener must never believe an
        // order exists that a later failure in the same transaction undid.
        event(new OrderPlaced($order));

        return $order;
    }

    private function resolvePromo(?string $input, $items, $lockedProducts): ?PromoCode
    {
        $input = $input === null ? '' : trim($input);

        if ($input === '') {
            return null;
        }

        $promo = PromoCode::query()
            ->whereRaw('UPPER(code) = ?', [Str::upper($input)])
            ->lockForUpdate()
            ->first();

        if (! $promo || ! $promo->isRedeemable()) {
            throw new CheckoutException('That promo code is not valid.');
        }

        $eligibleSubtotal = $items
            ->filter(fn (CartItem $item) => $lockedProducts[$item->product_id]->store_id === $promo->store_id)
            ->sum(fn (CartItem $item) => $item->quantity * $lockedProducts[$item->product_id]->price);

        if ($eligibleSubtotal <= 0) {
            throw new CheckoutException('That promo code doesn\'t apply to anything in your cart.');
        }

        return $promo;
    }

    private function shortStockMessage(Product $product, int $wanted): string
    {
        $left = max(0, (int) $product->stock);

        return $left === 0
            ? "\"{$product->name}\" is out of stock now. Remove it from your cart to continue."
            : "Only {$left} of \"{$product->name}\" left, and you have {$wanted} in your cart. Lower the quantity to continue.";
    }
}
