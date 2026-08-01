<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
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
 *
 * checkout() also dispatches App\Events\OrderPlaced once the order
 * transaction has committed — Dot.Emall's first real domain event
 * dispatch (a follow-up incremental pass; see App\Events\OrderPlaced's
 * docblock for why it's scoped to just this trigger).
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
     * Places an order from the current user's cart.
     *
     * Stock is locked, re-checked, and decremented atomically inside the
     * transaction (lockForUpdate() + a WHERE-guarded decrement) rather than
     * checked once beforehand — a pre-check outside the transaction is a
     * TOCTOU race: two concurrent checkouts on the last unit could both
     * pass the pre-check and both decrement, driving stock negative. Under
     * SQLite (this app's dev/test driver) lockForUpdate() is a no-op, so
     * the WHERE-guarded decrement is the layer that actually matters
     * everywhere; the lock is real protection under Postgres/MySQL.
     */
    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipping_address' => ['required', 'string', 'max:255'],
        ]);

        $shippingAddress = $validated['shipping_address'];

        $cartItemIds = CartItem::where('user_id', auth()->id())->pluck('id');

        if ($cartItemIds->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        try {
            $order = DB::transaction(function () use ($cartItemIds, $shippingAddress) {
                // Re-fetch cart items + lock their products inside the transaction,
                // ordered by product_id to avoid lock-ordering deadlocks between
                // concurrent checkouts that share products.
                $items = CartItem::whereIn('id', $cartItemIds)
                    ->with(['product.store.owner' => fn ($q) => $q])
                    ->get();

                $productIds = $items->pluck('product_id')->sort()->values();
                $lockedProducts = Product::whereIn('id', $productIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($items as $item) {
                    $product = $lockedProducts->get($item->product_id);

                    if (! $product || ! $product->is_active || $item->quantity > $product->stock) {
                        $available = $product->stock ?? 0;
                        throw new \RuntimeException(
                            "\"{$item->product->name}\" no longer has enough stock ({$available} left)."
                        );
                    }
                }

                $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * $lockedProducts[$item->product_id]->price);

                $order = Order::create([
                    'user_id'          => auth()->id(),
                    'status'           => 'pending',
                    'subtotal'         => $subtotal,
                    'total'            => $subtotal,
                    'shipping_address' => $shippingAddress,
                ]);

                $storesToNotify = [];

                foreach ($items as $item) {
                    $product = $lockedProducts[$item->product_id];
                    $lineTotal = $item->quantity * $product->price;

                    OrderItem::create([
                        'order_id'   => $order->id,
                        'product_id' => $item->product_id,
                        'quantity'   => $item->quantity,
                        'unit_price' => $product->price,
                        'total'      => $lineTotal,
                    ]);

                    // WHERE-guarded decrement: still checks stock >= quantity at
                    // write time, so even without a real row lock (SQLite) this
                    // can never drive stock negative — it just affects 0 rows and
                    // we catch that below.
                    $updated = Product::where('id', $product->id)
                        ->where('stock', '>=', $item->quantity)
                        ->decrement('stock', $item->quantity);

                    if ($updated === 0) {
                        throw new \RuntimeException(
                            "\"{$item->product->name}\" no longer has enough stock."
                        );
                    }

                    $storesToNotify[$item->product->store_id] = $item->product->store;
                }

                CartItem::whereIn('id', $cartItemIds)->delete();

                foreach ($storesToNotify as $store) {
                    $store->owner?->notify(new NewOrderNotification($order, $store));
                }

                return $order;
            });
        } catch (\RuntimeException $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }

        // Dispatched after the transaction has committed, not from inside
        // the closure — firing it mid-transaction would mean any later
        // failure in that same transaction still left listeners believing
        // an order existed.
        event(new OrderPlaced($order));

        return redirect()->route('orders.show', $order)->with('status', 'Order placed successfully.');
    }
}
