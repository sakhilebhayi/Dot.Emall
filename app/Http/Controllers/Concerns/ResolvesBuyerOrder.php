<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Order;
use App\Models\User;
use App\Services\Guests\GuestOrderAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One rule for who may act as the BUYER of an order, on the account routes and on the guest routes alike. A signed-in buyer must own the
 * order. A guest must have followed the order's magic link in THIS browser session (GuestOrderAccess), and the order must still be a guest
 * order. Anything else answers 404 so the pages reveal nothing about which orders exist. Sellers and operators are not buyers here.
 */
trait ResolvesBuyerOrder
{
    protected function isGuestRoute(Request $request): bool
    {
        return $request->routeIs('guest.*');
    }

    /** The signed-in buyer, or null for a verified guest. Aborts 404 when the caller is neither. */
    protected function buyerOf(Request $request, Order $order): ?User
    {
        if ($this->isGuestRoute($request)) {
            abort_unless($order->isGuest() && app(GuestOrderAccess::class)->hasSessionGrant($order), 404);

            return null;
        }

        abort_unless($request->user() !== null && $order->user_id === $request->user()->id, 404);

        return $request->user();
    }

    protected function orderPageRoute(Request $request, Order $order): RedirectResponse
    {
        return redirect()->to($this->orderUrl($request, $order));
    }

    protected function orderUrl(Request $request, Order $order, string $fragment = ''): string
    {
        return ($this->isGuestRoute($request) ? route('guest.orders.show', $order) : route('orders.show', $order)).$fragment;
    }

    /** Route name for an order action in the right family: 'orders.pay' or 'guest.orders.pay'. */
    protected function routeName(Request $request, string $name): string
    {
        return ($this->isGuestRoute($request) ? 'guest.' : '').'orders.'.$name;
    }
}
