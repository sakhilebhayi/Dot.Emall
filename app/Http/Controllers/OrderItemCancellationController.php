<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Notifications\OrderItemStatusUpdatedNotification;
use App\Notifications\OrderLineCancelledNotification;
use App\Services\OrderLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Cancel one line of an order -- the buyer who placed it, or the seller who
 * owns the line, until it ships. Restocking, re-pricing and giving the
 * promo use back all happen in App\Services\OrderLifecycle::cancelItem().
 */
class OrderItemCancellationController extends Controller
{
    public function __invoke(OrderItem $orderItem, OrderLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('cancel', $orderItem);

        $wasCancelled = $orderItem->isCancelled();
        $actor = auth()->user();

        try {
            $lifecycle->cancelItem($orderItem);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $orderItem->refresh();

        if (! $wasCancelled) {
            // Tell the party who did not click.
            if ($actor->id === $orderItem->order->user_id) {
                $orderItem->seller?->notify(new OrderLineCancelledNotification($orderItem));
            } else {
                $orderItem->order->user?->notify(new OrderItemStatusUpdatedNotification($orderItem));
            }
        }

        return back()->with('status', 'Order item cancelled.');
    }
}
