<?php

namespace App\Services\Discovery;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;
use App\Support\Actor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wishlists. Everything is private to the person unless they switch sharing on, which mints an unguessable link;
 * switching it off (or on again) kills the old link. A shared list shows products only, never the owner's notes
 * or name.
 */
class Wishlists
{
    public function __construct(private readonly Track $track) {}

    public function defaultFor(User $user): Wishlist
    {
        return Wishlist::query()->where('user_id', $user->id)->orderBy('id')->first()
            ?? Wishlist::query()->create(['user_id' => $user->id, 'name' => 'My list']);
    }

    /**
     * @throws ValidationException when the product cannot be saved or the list is full
     */
    public function add(User $user, Product $product, ?int $variantId = null, ?string $note = null): WishlistItem
    {
        if (! $this->isBrowsable($product)) {
            throw ValidationException::withMessages(['product' => 'This product is not available to save.']);
        }

        $list = $this->defaultFor($user);
        $key = $variantId ?? 0;

        $existing = WishlistItem::query()->where('wishlist_id', $list->id)->where('product_id', $product->id)->where('variant_key', $key)->first();

        if ($existing) {
            if ($note !== null) {
                $existing->update(['note' => Str::limit(trim($note), 255, '')]);
            }

            return $existing;
        }

        if ($list->items()->count() >= (int) config('discovery.wishlist.max_items', 200)) {
            throw ValidationException::withMessages(['product' => 'Your list is full. Remove something to add more.']);
        }

        DB::table('wishlist_items')->insertOrIgnore([
            'wishlist_id' => $list->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'variant_key' => $key,
            'note' => $note !== null ? Str::limit(trim($note), 255, '') : null,
            'price_when_added' => $product->price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->track->wishlistAdd(Actor::forUser($user), $product);

        return WishlistItem::query()->where('wishlist_id', $list->id)->where('product_id', $product->id)->where('variant_key', $key)->firstOrFail();
    }

    public function remove(User $user, Product|int $product, ?int $variantId = null): bool
    {
        $lists = Wishlist::query()->where('user_id', $user->id)->pluck('id');

        return WishlistItem::query()->whereIn('wishlist_id', $lists)
            ->where('product_id', $product instanceof Product ? $product->id : $product)
            ->where('variant_key', $variantId ?? 0)->delete() > 0;
    }

    /** Takes the product off the person's lists whatever variant was saved. */
    public function removeProduct(User $user, Product|int $product): bool
    {
        $lists = Wishlist::query()->where('user_id', $user->id)->pluck('id');

        return WishlistItem::query()->whereIn('wishlist_id', $lists)->where('product_id', $product instanceof Product ? $product->id : $product)->delete() > 0;
    }

    public function has(User $user, Product $product): bool
    {
        return WishlistItem::query()->whereIn('wishlist_id', Wishlist::query()->where('user_id', $user->id)->select('id'))
            ->where('product_id', $product->id)->exists();
    }

    /** Product ids on the person's lists, for drawing the saved state on a grid in one query. @return array<int, int> */
    public function productIdsFor(User $user): array
    {
        return WishlistItem::query()->whereIn('wishlist_id', Wishlist::query()->where('user_id', $user->id)->select('id'))
            ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The list with its products, newest first. Products that can no longer be bought are kept and flagged, so the
     * person sees why something stopped being available instead of it silently vanishing.
     *
     * @return Collection<int, WishlistItem>
     */
    public function items(User $user): Collection
    {
        $list = $this->defaultFor($user);

        return $list->items()->with(['product.store', 'product.images'])->get()
            ->each(fn (WishlistItem $item) => $item->setAttribute('available', $item->product && $this->isBrowsable($item->product) && $item->product->stock > 0));
    }

    public function updateNote(WishlistItem $item, ?string $note): WishlistItem
    {
        $item->update(['note' => $note === null || trim($note) === '' ? null : Str::limit(trim($note), 255, '')]);

        return $item;
    }

    /**
     * Puts one unit in the buyer's cart through the cart service (a cart line is a VARIANT) and takes it off the list.
     * A saved variant is used as saved; a product with a single choice uses that choice; a product with several choices
     * and none saved is not guessed at: the answer says so (`needs_variant`) and the item stays on the list.
     *
     * @return array{ok: bool, message: string, needs_variant?: bool}
     */
    public function moveToCart(User $user, WishlistItem $item): array
    {
        $product = $item->product;

        if ($product === null || ! $this->isBrowsable($product)) {
            return ['ok' => false, 'message' => 'This product is no longer available.'];
        }

        $active = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->orderBy('price')->orderBy('id')->get();
        $variant = $item->variant_id !== null ? $active->firstWhere('id', $item->variant_id) : null;

        if ($variant === null) {
            if ($active->count() > 1) {
                return ['ok' => false, 'needs_variant' => true, 'message' => '"'.$product->name.'" comes in several options. Choose one on the product page.'];
            }

            $variant = $active->first();
        }

        if ($variant === null || $variant->stock < 1) {
            return ['ok' => false, 'message' => '"'.$product->name.'" is out of stock. Set an alert to hear when it is back.'];
        }

        try {
            app(CartManager::class)->add($user, $variant, 1);
        } catch (CartProblem $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $item->delete();

        return ['ok' => true, 'message' => 'Moved "'.$product->name.'" to your cart.'];
    }

    /** Turns the public link on and returns its token. */
    public function enableSharing(Wishlist $list): string
    {
        $token = Str::random(40);
        $list->forceFill(['is_shared' => true, 'share_token' => $token])->save();

        return $token;
    }

    public function disableSharing(Wishlist $list): void
    {
        $list->forceFill(['is_shared' => false, 'share_token' => null])->save();
    }

    /**
     * What a visitor with the link sees: buyable products only, no notes, no owner.
     *
     * @return array{products: Collection<int, Product>, count: int}|null
     */
    public function shared(string $token): ?array
    {
        if (strlen($token) < 40) {
            return null;
        }

        $list = Wishlist::query()->where('share_token', $token)->where('is_shared', true)->first();

        if ($list === null) {
            return null;
        }

        $ids = $list->items()->pluck('product_id');
        $products = Product::query()->browsable()->whereIn('id', $ids)->with(['store', 'images'])->get();

        return ['products' => $products, 'count' => $products->count()];
    }

    private function isBrowsable(Product $product): bool
    {
        return Product::query()->browsable()->whereKey($product->id)->exists();
    }
}
