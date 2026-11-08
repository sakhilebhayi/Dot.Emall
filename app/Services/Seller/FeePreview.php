<?php

namespace App\Services\Seller;

use App\Models\Store;
use App\Services\Ledger\LedgerService;
use App\Support\Money;
use App\Support\Shop;

/**
 * "You receive R... after Emall's fee of ..." for a price a seller is typing. The same arithmetic as the ledger
 * (LedgerService::recordSale): the percentage is of the item price, rounded to whole cents, and the buyer's delivery fee is
 * not charged. Nothing here is a payment-processing cost: the ledger has none.
 */
class FeePreview
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @return array{price_cents: int, percent: float, fee_cents: int, receive_cents: int, sentence: string}
     */
    public function for(Store $store, float|int|string|null $price): array
    {
        $priceCents = max(0, Money::toCents(is_numeric($price) ? $price : 0));
        $percent = $this->ledger->commissionPercent($store->id);
        $fee = (int) round($priceCents * $percent / 100);
        $receive = $priceCents - $fee;

        $sentence = $percent <= 0
            ? 'Emall takes no fee on this sale at the moment: you receive '.Shop::money($receive).'.'
            : 'You receive '.Shop::money($receive).' after Emall\'s fee of '.Shop::money($fee).' ('.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% of the price).';

        return ['price_cents' => $priceCents, 'percent' => $percent, 'fee_cents' => $fee, 'receive_cents' => $receive, 'sentence' => $sentence];
    }
}
