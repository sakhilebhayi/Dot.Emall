<?php

namespace App\Services\Cart;

use App\Models\PromoCode;
use App\Services\Shipping\ShippingOption;

/**
 * What the buyer is shown before paying, and what checkout recomputes to compare against (expected_total_cents).
 *
 * @phpstan-type Group array{store_id: int, store_name: string, lines: array<int, CartLine>, items_cents: int, discount_cents: int, options: array<int, ShippingOption>, selected: ?ShippingOption, delivery_cents: int, vat_registered: bool, vat_cents: int, total_cents: int, remaining_for_free_cents: ?int, can_deliver: bool}
 */
final class QuoteResult
{
    /**
     * @param  array<int, array>  $groups  keyed by store id (see Group)
     * @param  array<int, string>  $problems  variant id => reason a line cannot be bought now
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $itemsCents,
        public readonly int $discountCents,
        public readonly int $deliveryCents,
        public readonly int $vatCents,
        public readonly int $totalCents,
        public readonly ?PromoCode $promo,
        public readonly ?string $promoProblem,
        public readonly array $problems,
        public readonly string $currency,
    ) {}

    /** Every line is buyable and every seller group can be delivered to the region. */
    public function canCheckout(): bool
    {
        return $this->problems === [] && $this->groups !== [] && collect($this->groups)->every(fn (array $g) => $g['can_deliver']);
    }
}
