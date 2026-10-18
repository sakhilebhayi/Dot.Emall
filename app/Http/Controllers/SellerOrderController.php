<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\OrderItemStatusUpdatedNotification;
use App\Services\OrderLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Seller-facing order fulfilment. A seller works with their own LINES of an
 * order, never the order as a whole: the list loads only this seller's lines
 * (so another seller's lines and the order total are never even fetched),
 * and status changes go through App\Services\OrderLifecycle so cancelling
 * always restocks and re-prices.
 */
class SellerOrderController extends Controller
{
    public function index(Request $request): View
    {
        $sellerId = $request->user()->id;

        $orders = Order::query()
            ->whereHas('items', fn ($q) => $q->where('seller_id', $sellerId))
            ->with([
                'user:id,name',
                'items' => fn ($q) => $q->where('seller_id', $sellerId),
            ])
            ->latest()
            ->paginate(15);

        return view('orders.seller-index', compact('orders'));
    }

    public function updateItemStatus(OrderItem $orderItem, Request $request, OrderLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('updateStatus', $orderItem);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
        ]);

        try {
            $lifecycle->advanceItem($orderItem, OrderStatus::from($validated['status']));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $orderItem->refresh();
        $orderItem->order->user?->notify(new OrderItemStatusUpdatedNotification($orderItem));

        return back()->with('status', 'Order item updated.');
    }
}
