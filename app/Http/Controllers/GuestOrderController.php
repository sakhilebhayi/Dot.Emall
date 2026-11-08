<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBuyerOrder;
use App\Models\Order;
use App\Services\Orders\OrderPage;
use App\Services\OrderAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guest's own page for one order (D-9, D-19). They get here by following the link in their e-mail (GuestOrderAccessController checks
 * the token and remembers it for this browser). Without that, or for an order that belongs to an account, the answer is the same 404 as a
 * wrong token. The page is the buyer's page minus account links, with an invitation to sign in with InfoDot to keep orders.
 */
class GuestOrderController extends Controller
{
    use ResolvesBuyerOrder;

    public function show(Request $request, Order $order, OrderAccess $access, OrderPage $page): View
    {
        $this->buyerOf($request, $order);

        return view('orders.buyer', $page->for($order, $access->presentGuest($order)) + ['mode' => 'guest']);
    }
}
