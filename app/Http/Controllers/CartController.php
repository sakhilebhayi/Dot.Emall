<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Notifications\NewOrderNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cart + checkout (platform-loop pass). Order and OrderItem were fully
 * modeled and CartItem existed with a unique(user_id, product_id)
 * constraint, but nothing in the codebase ever created an Order — there
 * was no checkout flow at all. This is the single biggest gap the wiki
 * flagged implicitly: "Order placed" was listed as a "conceptual" event
 * with no trigger anywhere (wiki.md §5). This controller is that trigger.
 *
 * Every query here is scoped to auth()->id() rather than a separate
 * CartItemPolicy — same inline-ownership pattern used elsewhere in this
 * platform loop (e.g. Dot.Auction's WatchlistButton) for a single-owner
 * resource with no shared/team access model.
 */
class CartController extends Controller
{
    public function index(): View
    {
        $items = CartItem::where('user_id', auth()->id())
            ->with(['product.store', 'product.images'])
            ->get();

        $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * $item->product->price);

        return view('cart.index', compact('items', 'subtotal'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::browsable()->findOrFail($validated['product_id']);
        $quantity = $validated['quantity'] ?? 1;

        $item = CartItem::firstOrNew([
            'user_id'    => auth()->id(),
            'product_id' => $product->id,
        ]);
        $item->quantity = min(99, ($item->exists ? $item->quantity : 0) + $quantity);
        $item->save();

        return redirect()->route('cart.index')->with('status', "Added \"{$product->name}\" to cart.");
    }

    public function destroy(CartItem $cartItem): RedirectResponse
    {
        abort_unless($cartItem->user_id === auth()->id(), 403);

        $cartItem->delete();

        return redirect()->route('cart.index')->with('status', 'Item removed from cart.');
    }

    /**
     * Places an order from the current user's cart. Stock is checked and
     * decremented per line item inside a transaction; the whole checkout
     * is rejected if any line item no longer has enough stock, rather
     * than partially fulfilling the order.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipping_address' => ['required', 'string', 'max:255'],
        ]);

        $shippingAddress = $validated['shipping_address'];

        $items = CartItem::where('user_id', auth()->id())
            ->with('product.store.owner')
            ->get();

        if ($items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        foreach ($items as $item) {
            if (! $item->product->is_active || $item->quantity > $item->product->stock) {
                return redirect()->route('cart.index')
                    ->with('error', "\"{$item->product->name}\" no longer has enough stock ({$item->product->stock} left).");
            }
        }

        $order = DB::transaction(function () use ($items, $shippingAddress) {
            $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * $item->product->price);

            $order = Order::create([
                'user_id'          => auth()->id(),
                'status'           => 'pending',
                'subtotal'         => $subtotal,
                'total'            => $subtotal,
                'shipping_address' => $shippingAddress,
            ]);

            $storesToNotify = [];

            foreach ($items as $item) {
                $lineTotal = $item->quantity * $item->product->price;

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->product->price,
                    'total'      => $lineTotal,
                ]);

                $item->product->decrement('stock', $item->quantity);
                $storesToNotify[$item->product->store_id] = $item->product->store;

                $item->delete();
            }

            foreach ($storesToNotify as $store) {
                $store->owner?->notify(new NewOrderNotification($order, $store));
            }

            return $order;
        });

        return redirect()->route('orders.show', $order)->with('status', 'Order placed successfully.');
    }
}
