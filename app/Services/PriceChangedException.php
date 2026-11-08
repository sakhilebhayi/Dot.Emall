<?php

namespace App\Services;

use App\Support\Shop;

/**
 * The total the buyer was shown is not what the server now computes (a seller changed a price or a delivery cost
 * between the cart page and the button). Nothing was placed or charged. The UI shows getMessage() and redraws the
 * cart with the new numbers; $actualCents is the recomputed total.
 */
class PriceChangedException extends CheckoutException
{
    public function __construct(public readonly int $expectedCents, public readonly int $actualCents)
    {
        parent::__construct('The price changed while you were checking out: it is now '.Shop::money($actualCents).', not '.Shop::money($expectedCents).'. Nothing was charged. Please check your cart and confirm again.');
    }
}
