<?php

namespace App\Http\Controllers;

use App\Enums\ReturnStatus;
use App\Models\Address;
use App\Models\Order;
use App\Models\ProductAlert;
use App\Models\ReturnRequest;
use App\Models\SavedSearch;
use App\Services\Discovery\Wishlists;
use App\Services\Orders\OrderPage;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer's overview. NOT a seller dashboard: recent orders, returns that need the buyer, what they saved, alerts, searches and their
 * default address. Sellers reach their desk from seller.dashboard; /dashboard sends each person to the right one.
 */
class AccountController extends Controller
{
    public function home(Request $request, OrderPage $page, Wishlists $wishlists): View
    {
        $user = $request->user();
        $orders = Order::query()->where('user_id', $user->id)->with(['items', 'groups'])->latest('id')->limit(4)->get();

        return view('account.home', [
            'user' => $user,
            'orders' => $orders,
            'statuses' => $orders->mapWithKeys(fn (Order $o) => [$o->id => $page->status($o, $o->items)['key']]),
            'awaiting' => Order::query()->where('user_id', $user->id)->where('requires_payment', true)->whereIn('payment_status', ['unpaid', 'pending', 'failed'])->where('status', '!=', 'cancelled')->count(),
            'returnsNeedingYou' => ReturnRequest::query()->where('requester_id', $user->id)->whereIn('status', [ReturnStatus::Declined->value, ReturnStatus::Approved->value])->with('item')->latest('id')->limit(4)->get(),
            'openReturns' => ReturnRequest::query()->where('requester_id', $user->id)->whereIn('status', [ReturnStatus::Requested->value, ReturnStatus::Approved->value, ReturnStatus::Received->value, ReturnStatus::Escalated->value])->count(),
            'wishlist' => $wishlists->items($user)->take(4),
            'alerts' => ProductAlert::query()->where('user_id', $user->id)->where('active', true)->count(),
            'searches' => SavedSearch::query()->where('user_id', $user->id)->count(),
            'addressCount' => Address::query()->where('user_id', $user->id)->count(),
            'defaultAddress' => Address::query()->where('user_id', $user->id)->where('is_default_shipping', true)->first(),
            'unread' => $user->unreadNotifications()->count(),
            'sells' => $user->stores()->exists(),
        ]);
    }
}
