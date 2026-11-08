<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Base of the seller desk. A seller works on ONE store, the one they own (User::store()); nothing in a seller URL names a store,
 * so there is no store id to swap. Anyone without a store (a buyer, an operator, a colleague in the owner's team) is sent to the
 * "Sell on Emall" page, and every query below this class is scoped to the store this method returns.
 */
abstract class SellerController extends Controller
{
    protected function myStore(Request $request): Store
    {
        $store = $request->user()?->store;

        if (! $store) {
            throw new HttpResponseException(redirect()->route('sell.start'));
        }

        return $store;
    }
}
