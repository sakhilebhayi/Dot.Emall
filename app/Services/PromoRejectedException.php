<?php

namespace App\Services;

/**
 * A promo code that could not be applied. Deliberately one message for
 * every reason (unknown, expired, used up, inactive, belongs to a store
 * that is not in the cart) so that guessing codes teaches an attacker
 * nothing about which ones exist.
 */
class PromoRejectedException extends CheckoutException
{
    public function __construct()
    {
        parent::__construct('That promo code is not valid for this order.');
    }
}
