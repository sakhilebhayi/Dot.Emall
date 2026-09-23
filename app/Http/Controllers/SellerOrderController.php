<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Notifications\OrderItemStatusUpdatedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Seller-facing order fulfillment -- previously a seller had no way to see
 * which orders needed fulfilling or move them forward at all (wiki.md §5:
 * "no route or controller changes [order status] after creation today").
 * The buyer-facing order detail page (OrderController::show(), same
 * `/orders/{order}` route) is reused for sellers too -- OrderPolicy::view()
 * now also allows a seller with items in the order, and the view itself
 * conditionally renders per-item status controls for items the viewer owns.
 */
class SellerOrderController extends Controller
{
    public function index(Request $request): View
    {
        $storeIds = Store::where('owner_id', $request->user()->id)->pluck('id');

        $orderIds = OrderItem::whereHas('product', fn ($q) => $q->whereIn('store_id', $storeIds))
            ->pluck('order_id')
            ->unique();

        $orders = Order::whereIn('id', $orderIds)
            ->with(['user', 'items.product.store'])
            ->latest()
            ->paginate(15);

        return view('orders.seller-index', compact('orders'));
    }

    public function updateItemStatus(OrderItem $orderItem, Request $request): RedirectResponse
    {
        Gate::authorize('updateStatus', $orderItem);

        $validated = $request->validate([
            'status' => ['required', Rule::in(OrderItem::STATUSES)],
        ]);

        // Forward-only: a delivered or cancelled item is final, and status
        // can't be walked backward (e.g. shipped -> processing) -- matches
        // how a real fulfillment lifecycle only ever moves forward.
        $currentIndex = array_search($orderItem->status, OrderItem::STATUSES, true);
        $nextIndex = array_search($validated['status'], OrderItem::STATUSES, true);

        if ($orderItem->status === 'cancelled' || $orderItem->status === 'delivered' || $nextIndex <= $currentIndex) {
            return back()->withErrors(['status' => 'That status change is not allowed.']);
        }

        $orderItem->update(['status' => $validated['status']]);
        $orderItem->order->recomputeStatusFromItems();

        $orderItem->order->user?->notify(new OrderItemStatusUpdatedNotification($orderItem));

        return back()->with('status', 'Order item updated.');
    }
}
