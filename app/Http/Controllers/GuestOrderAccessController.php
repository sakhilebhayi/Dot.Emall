<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Guests\GuestOrderAccess;
use App\Services\OrderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * GET /guest/orders/{order}/{token}: the magic link in a guest's e-mail. A wrong, rotated, revoked or expired token,
 * an order that belongs to an account, and an order that does not exist all answer the same 404, so the endpoint
 * reveals nothing about which order ids exist. A valid token stores a grant for this one order in the session and
 * sends the guest to the order page (the named route guest.orders.show when the storefront provides one; until
 * then a small JSON summary).
 */
class GuestOrderAccessController extends Controller
{
    public function __invoke(Request $request, Order $order, string $token, GuestOrderAccess $access, OrderAccess $orders): RedirectResponse|Response|JsonResponse
    {
        abort_unless($access->verify($order, $token), 404);

        $access->grantSession($order);
        $request->session()->regenerate();

        if (Route::has('guest.orders.show') && ! $request->wantsJson()) {
            return redirect()->route('guest.orders.show', $order);
        }

        $view = $orders->presentGuest($order);

        return response()->json([
            'order' => $order->reference(),
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'total' => $order->amount($order->total),
            'lines' => $view['lines']->map(fn ($line) => ['name' => $line->displayName(), 'quantity' => $line->quantity, 'status' => $line->status->value])->values(),
        ]);
    }
}
