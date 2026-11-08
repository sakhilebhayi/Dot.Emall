<?php

namespace App\Services\Cart;

use App\Models\PromoCode;
use App\Services\Shipping\Region;
use App\Services\Shipping\ShippingQuote;
use App\Support\Money;
use App\Support\Shop;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The total-price-first view of a cart (D-3): items, promo, delivery per seller group, VAT component and the total, for a
 * destination region, all server-side. Uses the same arithmetic as checkout (Money::discountCents, ShippingQuote,
 * Vat), so the number shown here is the number checkout compares against and then charges.
 *
 * A promo code here is previewed without locking or counting a use; checkout resolves it for real.
 */
class CartQuote
{
    public function __construct(private readonly ShippingQuote $shipping) {}

    /**
     * @param  Collection<int, CartLine>  $lines
     * @param  array<int, int|null>  $methodChoices  store id => chosen shipping method id (missing = cheapest)
     */
    public function quote(Collection $lines, ?Region $region = null, ?string $promoCode = null, array $methodChoices = [], ?Carbon $today = null): QuoteResult
    {
        $problems = [];
        foreach ($lines as $line) {
            if (($problem = $line->problem()) !== null) {
                $problems[$line->variant->id] = $problem;
            }
        }

        $byStore = $lines->groupBy(fn (CartLine $l) => $l->storeId());
        $itemsByStore = $byStore->map(fn (Collection $group) => $group->sum(fn (CartLine $l) => $l->totalCents()))->all();

        [$promo, $promoProblem] = $this->previewPromo($promoCode, $itemsByStore);
        $discountByStore = [];

        if ($promo !== null) {
            $discountByStore[$promo->store_id] = Money::discountCents($promo->discount_type, $promo->discount_value, (int) ($itemsByStore[$promo->store_id] ?? 0));
        }

        $quotes = $this->shipping->forStores($itemsByStore, $region, $today);
        $groups = [];
        $totals = ['items' => 0, 'discount' => 0, 'delivery' => 0, 'vat' => 0];

        foreach ($byStore as $storeId => $group) {
            $quote = $quotes[$storeId] ?? null;
            $items = (int) $itemsByStore[$storeId];
            $discount = (int) ($discountByStore[$storeId] ?? 0);
            $choice = $methodChoices[$storeId] ?? null;
            $selected = $quote === null ? null : ($choice !== null ? ($quote->option((int) $choice) ?? $quote->cheapest()) : $quote->cheapest());

            $delivery = $selected?->priceCents ?? 0;

            if ($promo !== null && $promo->free_shipping && (int) $promo->store_id === (int) $storeId) {
                $delivery = 0;
            }

            $store = $group->first()->product->store;
            $vat = $store->vat_registered ? Vat::includedIn($items - $discount + $delivery) : 0;

            $groups[$storeId] = [
                'store_id' => (int) $storeId,
                'store_name' => $store->name,
                'lines' => $group->values()->all(),
                'items_cents' => $items,
                'discount_cents' => $discount,
                'options' => $quote?->options ?? [],
                'selected' => $selected,
                'delivery_cents' => $delivery,
                'vat_registered' => (bool) $store->vat_registered,
                'vat_cents' => $vat,
                'total_cents' => $items - $discount + $delivery,
                'remaining_for_free_cents' => $selected?->remainingForFree($items),
                'can_deliver' => $quote?->canDeliver() ?? false,
            ];

            $totals['items'] += $items;
            $totals['discount'] += $discount;
            $totals['delivery'] += $delivery;
            $totals['vat'] += $vat;
        }

        return new QuoteResult(
            $groups, $totals['items'], $totals['discount'], $totals['delivery'], $totals['vat'],
            $totals['items'] - $totals['discount'] + $totals['delivery'], $promo, $promoProblem, $problems, Shop::currency(),
        );
    }

    /**
     * The same choice checkout makes: among the carted stores' redeemable codes with this text, the larger saving wins,
     * ties go to the lower store id.
     *
     * @param  array<int, int>  $itemsByStore
     * @return array{0: ?PromoCode, 1: ?string}
     */
    private function previewPromo(?string $code, array $itemsByStore): array
    {
        $code = trim((string) $code);

        if ($code === '') {
            return [null, null];
        }

        $best = null;
        $bestDiscount = 0;

        $candidates = PromoCode::query()
            ->whereIn('store_id', array_keys($itemsByStore))
            ->whereRaw('UPPER(code) = ?', [Str::upper($code)])
            ->orderBy('store_id')->get()
            ->filter(fn (PromoCode $p) => $p->isRedeemable());

        foreach ($candidates as $promo) {
            $eligible = (int) ($itemsByStore[$promo->store_id] ?? 0);
            $discount = Money::discountCents($promo->discount_type, $promo->discount_value, $eligible);

            if ($eligible > 0 && $discount > $bestDiscount) {
                $best = $promo;
                $bestDiscount = $discount;
            } elseif ($eligible > 0 && $best === null) {
                $best = $promo;
            }
        }

        return $best === null ? [null, 'That code is not valid for the items in your cart.'] : [$best, null];
    }
}
