<?php

namespace App\Services\Ledger;

use App\Models\Store;

/**
 * The fee a seller is told BEFORE they publish a listing (D-2), in words that match what the ledger does.
 */
class SellerFees
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function percent(?Store $store = null): float
    {
        return $this->ledger->commissionPercent($store?->id);
    }

    /**
     * @return string the sentence(s) to show next to the publish button
     */
    public function disclosure(?Store $store = null): string
    {
        $percent = $this->percent($store);
        $days = (int) config('emall.commission.release_after_days', 7);

        $fee = $percent <= 0
            ? 'Dot.Emall takes no commission on your sales at the moment. If that changes you will see the new percentage here before it applies to a new sale, and sales already made keep the percentage they were made under.'
            : 'Dot.Emall takes '.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'% of the item price (after any discount you gave) on each sale. It is not charged on delivery fees you set, and the percentage on a sale is fixed when the buyer pays.';

        return $fee." Your earnings are recorded in your ledger when the buyer pays and are released {$days} days after delivery. Dot.Emall does not pay out automatically yet.";
    }
}
