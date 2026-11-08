<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductAlert;
use App\Models\ProductVariant;
use App\Models\WishlistItem;
use App\Services\Discovery\ProductAlerts;
use App\Services\Discovery\Wishlists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The wishlist: the person's page (signed in), the heart on every product card (a form that works without script, enhanced by
 * resources/js/product.js), the share switch, and the public read-only page behind a share link.
 */
class WishlistController extends Controller
{
    public function index(Request $request, Wishlists $wishlists): View
    {
        $user = $request->user();
        $list = $wishlists->defaultFor($user);
        $items = $wishlists->items($user);

        // The variant each item was saved with, and the alerts already set, in one query each.
        $variants = ProductVariant::query()->whereIn('id', $items->pluck('variant_id')->filter())->get()->keyBy('id');
        $alerts = ProductAlert::query()->where('user_id', $user->id)->where('active', true)->whereIn('product_id', $items->pluck('product_id'))->get()
            ->groupBy('product_id');

        return view('wishlist.index', [
            'list' => $list,
            'items' => $items,
            'variants' => $variants,
            'alerts' => $alerts,
            'shareUrl' => $list->is_shared && $list->share_token ? route('wishlist.shared', $list->share_token) : null,
            // The token is hidden from serialisation; read it deliberately here, for its owner only.
        ]);
    }

    /** The heart: add the product, or take it off when it is already there. */
    public function toggle(Request $request, Product $product, Wishlists $wishlists): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $variantId = $request->integer('variant_id') ?: null;

        if ($variantId !== null && ! ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->whereKey($variantId)->exists()) {
            $variantId = null;
        }

        try {
            if ($wishlists->has($user, $product)) {
                $wishlists->removeProduct($user, $product);
                $saved = false;
                $message = 'Removed "'.$product->name.'" from your wishlist.';
            } else {
                $wishlists->add($user, $product, $variantId);
                $saved = true;
                $message = 'Saved "'.$product->name.'" to your wishlist.';
            }
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json(['saved' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
            }

            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        if ($request->expectsJson()) {
            return response()->json(['saved' => $saved, 'message' => $message]);
        }

        return back()->with('status', $message);
    }

    public function destroy(Request $request, WishlistItem $item, Wishlists $wishlists): RedirectResponse
    {
        Gate::authorize('update', $item->wishlist);

        $item->delete();

        return back()->with('status', 'Removed from your wishlist.');
    }

    public function toCart(Request $request, WishlistItem $item, Wishlists $wishlists): RedirectResponse
    {
        Gate::authorize('update', $item->wishlist);
        $item->loadMissing('product');

        $result = $wishlists->moveToCart($request->user(), $item);

        if ($result['ok']) {
            return back()->with('status', $result['message'])->with('cart_link', true);
        }

        // A product that comes in several options is not guessed at: the shopper picks on its page.
        if (! empty($result['needs_variant'])) {
            return redirect()->route('products.show', ['product' => $item->product_id, 'src' => 'wishlist'])->with('error', $result['message']);
        }

        return back()->with('error', $result['message']);
    }

    public function sharing(Request $request, Wishlists $wishlists): RedirectResponse
    {
        $list = $wishlists->defaultFor($request->user());
        Gate::authorize('update', $list);

        if ($request->boolean('share')) {
            $wishlists->enableSharing($list);

            return back()->with('status', 'Sharing is on. Anyone with the link can see the products on your list, and nothing else.');
        }

        $wishlists->disableSharing($list);

        return back()->with('status', 'Sharing is off. The old link no longer works.');
    }

    /** Price-drop and back-in-stock alerts, per wishlist item. */
    public function alert(Request $request, WishlistItem $item, ProductAlerts $alerts): RedirectResponse
    {
        Gate::authorize('update', $item->wishlist);
        $data = $request->validate(['kind' => ['required', 'in:'.ProductAlert::PRICE_DROP.','.ProductAlert::BACK_IN_STOCK], 'on' => ['required', 'boolean']]);

        $product = Product::query()->browsable()->find($item->product_id);

        if ($product === null) {
            return back()->with('error', 'This product is no longer available, so there is nothing to watch.');
        }

        $existing = ProductAlert::query()->where('user_id', $request->user()->id)->where('product_id', $product->id)
            ->where('variant_key', $item->variant_id ?? 0)->where('kind', $data['kind'])->where('active', true)->first();

        if (! $data['on']) {
            if ($existing) {
                $alerts->cancel($existing);
            }

            return back()->with('status', 'Alert turned off.');
        }

        try {
            $alerts->subscribe($request->user(), $product, $data['kind'], null, $item->variant_id);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('status', $data['kind'] === ProductAlert::PRICE_DROP ? 'We will tell you if the price drops below what it is now.' : 'We will tell you when it is back in stock.');
    }

    /** Public, read-only, behind an unguessable link the owner can switch off. */
    public function shared(Wishlists $wishlists, string $token): View
    {
        $shared = $wishlists->shared($token);

        abort_if($shared === null, 404);

        return view('wishlist.shared', ['products' => $shared['products']]);
    }
}
