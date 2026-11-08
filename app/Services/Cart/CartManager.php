<?php

namespace App\Services\Cart;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One cart API for an account (rows in cart_items) and a guest (a session array of variant id => quantity). A guest's
 * cart is merged into the account's when they sign in (MergeGuestCart listener): quantities are added and capped to
 * stock, and lines that can no longer be bought are dropped.
 *
 * The cart only ever holds a variant id and a quantity. Prices and stock are read fresh every time and re-checked at
 * checkout; nothing the browser sends is believed.
 */
class CartManager
{
    public const SESSION_KEY = 'emall.guest_cart';

    public const MAX_PER_LINE = 99;

    public const MAX_LINES = 50;

    /**
     * @return Collection<int, CartLine>
     */
    public function lines(?User $user): Collection
    {
        $rows = $user !== null
            ? CartItem::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get(['id', 'variant_id', 'quantity'])
                ->map(fn ($row) => ['id' => $row->id, 'variant_id' => (int) $row->variant_id, 'quantity' => (int) $row->quantity])
            : collect($this->guestRaw())->map(fn ($qty, $variantId) => ['id' => null, 'variant_id' => (int) $variantId, 'quantity' => (int) $qty])->values();

        if ($rows->isEmpty()) {
            return collect();
        }

        $variants = ProductVariant::query()
            ->whereIn('id', $rows->pluck('variant_id'))
            ->with(['product' => fn ($q) => $q->withTrashed()->with('store', 'images')])
            ->get()->keyBy('id');

        return $rows->map(function (array $row) use ($variants) {
            $variant = $variants->get($row['variant_id']);

            return $variant?->product === null ? null : new CartLine($variant, $variant->product, $row['quantity'], $row['id']);
        })->filter()->values();
    }

    public function count(?User $user): int
    {
        return $user !== null
            ? (int) CartItem::withoutGlobalScopes()->where('user_id', $user->id)->sum('quantity')
            : (int) array_sum($this->guestRaw());
    }

    /**
     * @throws CartProblem
     */
    public function add(?User $user, ProductVariant $variant, int $quantity = 1): void
    {
        $variant->loadMissing('product.store');
        $product = $variant->product;

        if (! self::isBuyable($variant, $product)) {
            throw new CartProblem('This item is not available.');
        }

        if ($user !== null && $product->store->owner_id === $user->id) {
            throw new CartProblem('This is your own listing, so you cannot buy it.', 'product_id');
        }

        $quantity = max(1, $quantity);

        if ($user === null) {
            $cart = $this->guestRaw();

            if (! isset($cart[$variant->id]) && count($cart) >= self::MAX_LINES) {
                throw new CartProblem('Your cart is full. Check out or remove something first.');
            }

            $already = (int) ($cart[$variant->id] ?? 0);
            $this->assertQuantity($variant, $product, $already + $quantity, $already);
            $cart[$variant->id] = $already + $quantity;
            session([self::SESSION_KEY => $cart]);

            return;
        }

        DB::transaction(function () use ($user, $variant, $product, $quantity) {
            // "Insert unless it is there" instead of insert-and-catch: one line per buyer and variant is a unique index, a
            // double click sends two requests that both found no line, and PostgreSQL poisons a transaction on a unique
            // violation (ON CONFLICT DO NOTHING does not).
            $inserted = DB::table('cart_items')->insertOrIgnore([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $line = CartItem::withoutGlobalScopes()->where('user_id', $user->id)->where('variant_id', $variant->id)->lockForUpdate()->firstOrFail();
            $already = (int) $line->quantity;

            try {
                $this->assertQuantity($variant, $product, $already + $quantity, $already);
            } catch (CartProblem $e) {
                if ($inserted === 1 && $already === 0) {
                    $line->delete();
                }

                throw $e;
            }

            $line->update(['quantity' => $already + $quantity]);
        });
    }

    /**
     * @throws CartProblem
     */
    public function setQuantity(?User $user, int $variantId, int $quantity): void
    {
        $variant = ProductVariant::query()->with('product.store')->findOrFail($variantId);

        if (! self::isBuyable($variant, $variant->product)) {
            throw new CartProblem("\"{$variant->product->name}\" is no longer available. Remove it from your cart.");
        }

        $this->assertQuantity($variant, $variant->product, $quantity, 0);

        if ($user === null) {
            $cart = $this->guestRaw();

            if (isset($cart[$variantId])) {
                $cart[$variantId] = $quantity;
                session([self::SESSION_KEY => $cart]);
            }

            return;
        }

        CartItem::withoutGlobalScopes()->where('user_id', $user->id)->where('variant_id', $variantId)->update(['quantity' => $quantity]);
    }

    public function remove(?User $user, int $variantId): void
    {
        if ($user === null) {
            $cart = $this->guestRaw();
            unset($cart[$variantId]);
            session([self::SESSION_KEY => $cart]);

            return;
        }

        CartItem::withoutGlobalScopes()->where('user_id', $user->id)->where('variant_id', $variantId)->delete();
    }

    public function clear(?User $user): void
    {
        if ($user === null) {
            session()->forget(self::SESSION_KEY);

            return;
        }

        CartItem::withoutGlobalScopes()->where('user_id', $user->id)->delete();
    }

    /**
     * Folds the session cart into the account's cart and empties the session cart. Returns how many lines moved.
     */
    public function mergeGuestInto(User $user): int
    {
        $guest = $this->guestRaw();

        if ($guest === []) {
            return 0;
        }

        session()->forget(self::SESSION_KEY);
        $moved = 0;

        foreach (ProductVariant::query()->whereIn('id', array_keys($guest))->with('product.store')->get() as $variant) {
            try {
                $already = (int) CartItem::withoutGlobalScopes()->where('user_id', $user->id)->where('variant_id', $variant->id)->value('quantity');
                $want = min(self::MAX_PER_LINE, $already + (int) $guest[$variant->id], max(1, (int) $variant->stock));

                if ($want > $already) {
                    $this->add($user, $variant, $want - $already);
                    $moved++;
                }
            } catch (CartProblem) {
                // Out of stock, off sale, or the buyer's own listing: not carried over.
            }
        }

        return $moved;
    }

    public static function isBuyable(ProductVariant $variant, ?Product $product): bool
    {
        return $product !== null
            && $variant->is_active
            && ! $product->trashed()
            && $product->is_active
            && $product->status === 'approved'
            && (bool) $product->store?->is_active;
    }

    /**
     * @throws CartProblem
     */
    private function assertQuantity(ProductVariant $variant, Product $product, int $wanted, int $already): void
    {
        $name = $variant->label() ? "{$product->name} ({$variant->label()})" : $product->name;

        if ($variant->stock < 1) {
            throw new CartProblem("\"{$name}\" is out of stock.");
        }

        if ($wanted > $variant->stock) {
            throw new CartProblem($already > 0
                ? "Only {$variant->stock} of \"{$name}\" in stock, and you already have {$already} in your cart."
                : "Only {$variant->stock} of \"{$name}\" in stock.");
        }

        if ($wanted > self::MAX_PER_LINE) {
            throw new CartProblem('You can order at most '.self::MAX_PER_LINE.' of one item at a time.');
        }
    }

    /** @return array<int|string, int> */
    private function guestRaw(): array
    {
        $cart = session(self::SESSION_KEY, []);

        return is_array($cart) ? array_filter($cart, fn ($qty) => is_int($qty) && $qty > 0) : [];
    }
}
