<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Cart and the checkout entry point. CartItem applies
 * App\Models\Concerns\HasUserScope, so every cart query is already scoped
 * to the signed-in user and another user's cart line 404s on route-model
 * binding. user_id is still set explicitly at create time.
 */
class CartController extends Controller
{
    public function index(): View
    {
        $items = CartItem::with(['product.store', 'product.images'])->get();

        $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * $item->product->price);

        return view('cart.index', compact('items', 'subtotal'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::browsable()->findOrFail($validated['product_id']);
        $quantity = $validated['quantity'] ?? 1;

        $item = CartItem::firstOrNew([
            'user_id' => auth()->id(),
            'product_id' => $product->id,
        ]);
        $item->quantity = min(99, ($item->exists ? $item->quantity : 0) + $quantity);
        $item->save();

        return redirect()->route('cart.index')->with('status', "Added \"{$product->name}\" to cart.");
    }

    public function destroy(CartItem $cartItem): RedirectResponse
    {
        $cartItem->delete();

        return redirect()->route('cart.index')->with('status', 'Item removed from cart.');
    }

    /**
     * Places an order from the current user's cart. The work -- stock locks,
     * promo handling, line snapshots, the after-commit event -- lives in
     * App\Services\CheckoutService. No payment is taken: the order is
     * placed "pending, unpaid".
     */
    public function checkout(Request $request, CheckoutService $checkout): RedirectResponse
    {
        $validated = $request->validate([
            'shipping_name' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $order = $checkout->place($request->user(), $validated);
        } catch (CheckoutException $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }

        return redirect()->route('orders.show', $order)->with('status', 'Order placed. It is pending and unpaid: payment is not available yet.');
    }
}
