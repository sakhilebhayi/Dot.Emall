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
use App\Support\Throttles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
     * @param  string|null  $token  the form's single-use token; the same token never places two orders
     * @param  string|null  $ip  the buyer's address, for the promo guess budget
     *
     * @throws CheckoutException
     */
    public function place(User $buyer, array $input, ?string $token = null, ?string $ip = null): Order
    {
        // Idempotency comes first: a replay arrives with an already-emptied
        // cart, which must not read as "your cart is empty".
        if ($token !== null && ($existing = $this->orderForToken($buyer, $token)) !== null) {
            return $existing;
        }

        $promoInput = trim((string) ($input['promo_code'] ?? ''));

        if ($promoInput !== '') {
            $this->guardPromoBudget($buyer, $ip);
        }

        $cartItemIds = CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->pluck('id');

        if ($cartItemIds->isEmpty()) {
            throw new CheckoutException('Your cart is empty.');
        }

        try {
            $order = $this->createOrder($buyer, $input, $cartItemIds, $token);
        } catch (PromoRejectedException $e) {
            // Counted here, after the transaction has rolled back, so the
            // guess is remembered whatever the cache store is.
            RateLimiter::hit(Throttles::promoUserKey($buyer->id), Throttles::PROMO_WINDOW_SECONDS);
            RateLimiter::hit(Throttles::promoIpKey($ip), Throttles::PROMO_WINDOW_SECONDS);

            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            // Two submissions of the same token raced past the check above;
            // the unique index let exactly one through.
            $existing = $token !== null ? $this->orderForToken($buyer, $token) : null;

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        // After the commit, not inside it: a listener must never believe an
        // order exists that a later failure in the same transaction undid.
        event(new OrderPlaced($order));

        return $order;
    }

    private function orderForToken(User $buyer, string $token): ?Order
    {
        return Order::query()->where('user_id', $buyer->id)->where('idempotency_key', $token)->first();
    }

    private function guardPromoBudget(User $buyer, ?string $ip): void
    {
        foreach ([Throttles::promoUserKey($buyer->id), Throttles::promoIpKey($ip)] as $key) {
            if (RateLimiter::tooManyAttempts($key, Throttles::PROMO_MAX_FAILURES)) {
                $seconds = RateLimiter::availableIn($key);

                throw new CheckoutException("Too many promo code attempts. Try again in {$seconds} seconds, or place the order without a code.");
            }
        }
    }

    private function createOrder(User $buyer, array $input, Collection $cartItemIds, ?string $token): Order
    {
        return DB::transaction(function () use ($buyer, $input, $cartItemIds, $token) {
            $items = CartItem::withoutGlobalScopes()
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
                'idempotency_key' => $token,
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

            CartItem::withoutGlobalScopes()->where('user_id', $buyer->id)->whereIn('id', $cartItemIds)->delete();

            return $order;
        });
    }

    /**
     * Finds the code among the stores actually in the cart. Codes are unique
     * per store, not globally, so the same text can exist in several stores;
     * only a store whose items are being bought can supply the discount, and
     * if more than one carted store has it the buyer gets the larger saving
     * (ties go to the lower store id, so the answer is deterministic).
     *
     * @throws PromoRejectedException
     */
    private function resolvePromo(?string $input, Collection $items, Collection $lockedProducts): ?PromoCode
    {
        $input = $input === null ? '' : trim($input);

        if ($input === '') {
            return null;
        }

        $storeIds = $items->map(fn (CartItem $item) => $lockedProducts[$item->product_id]->store_id)->unique()->values();

        $candidates = PromoCode::query()
            ->whereIn('store_id', $storeIds)
            ->whereRaw('UPPER(code) = ?', [Str::upper($input)])
            ->orderBy('store_id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (PromoCode $promo) => $promo->isRedeemable());

        $best = null;
        $bestDiscount = 0;

        foreach ($candidates as $promo) {
            $eligibleCents = $items
                ->filter(fn (CartItem $item) => $lockedProducts[$item->product_id]->store_id === $promo->store_id)
                ->sum(fn (CartItem $item) => $item->quantity * Money::toCents($lockedProducts[$item->product_id]->price));

            $discount = Money::discountCents($promo->discount_type, $promo->discount_value, $eligibleCents);

            if ($eligibleCents > 0 && $discount > $bestDiscount) {
                $best = $promo;
                $bestDiscount = $discount;
            } elseif ($eligibleCents > 0 && $best === null) {
                $best = $promo;
            }
        }

        if ($best === null) {
            throw new PromoRejectedException;
        }

        return $best;
    }

    private function shortStockMessage(Product $product, int $wanted): string
    {
        $left = max(0, (int) $product->stock);

        return $left === 0
            ? "\"{$product->name}\" is out of stock now. Remove it from your cart to continue."
            : "Only {$left} of \"{$product->name}\" left, and you have {$wanted} in your cart. Lower the quantity to continue.";
    }
}
