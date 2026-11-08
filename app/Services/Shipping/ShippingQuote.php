<?php

namespace App\Services\Shipping;

use App\Models\ShippingMethod;
use App\Models\ShippingMethodRegion;
use App\Models\Store;
use App\Services\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Prices and dates delivery for each seller group of a cart, server-side. Nothing here is taken from the browser:
 * checkout recomputes it from the stores' own shipping methods and the address's region and refuses a method the
 * store does not offer for that region.
 *
 * A method applies when it has no region rules, or when one of its rules matches the region (country, then optionally
 * province, then optionally an inclusive postal-code range). With an unknown region (no address yet) only methods
 * without rules apply. Collection always applies. A store with no active method at all falls back to "arranged with the
 * seller" at no extra charge, unless config emall.shipping.require_methods is on.
 */
class ShippingQuote
{
    /**
     * @param  array<int, int>  $itemsByStore  store id => item value in cents for that store (after nothing: promo free shipping is applied by the caller)
     * @return array<int, GroupQuote> keyed by store id
     */
    public function forStores(array $itemsByStore, ?Region $region, ?Carbon $today = null): array
    {
        $today ??= now();
        $stores = Store::query()->whereIn('id', array_keys($itemsByStore))->with(['shippingMethods' => fn ($q) => $q->where('is_active', true), 'shippingMethods.regions'])->get()->keyBy('id');

        $quotes = [];

        foreach ($itemsByStore as $storeId => $itemsCents) {
            $store = $stores->get($storeId);

            if ($store === null) {
                continue;
            }

            $options = $this->optionsFor($store->shippingMethods, $itemsCents, $region, $today);

            if ($store->shippingMethods->isEmpty() && ! config('emall.shipping.require_methods')) {
                $options = [$this->arranged()];
            }

            usort($options, fn (ShippingOption $a, ShippingOption $b) => [$a->priceCents, $a->windowMaxDays] <=> [$b->priceCents, $b->windowMaxDays]);

            $quotes[$storeId] = new GroupQuote($store->id, $store->name, $itemsCents, $options);
        }

        return $quotes;
    }

    /**
     * One store's option for a chosen method id (null = whatever is cheapest), or an exception a buyer can read.
     *
     * @throws CheckoutException
     */
    public function resolve(int $storeId, ?int $methodId, int $itemsCents, ?Region $region, ?Carbon $today = null): ShippingOption
    {
        $quote = $this->forStores([$storeId => $itemsCents], $region, $today)[$storeId] ?? null;

        if ($quote === null || ! $quote->canDeliver()) {
            $name = $quote?->storeName ?? 'This seller';

            throw new CheckoutException("{$name} does not deliver to this address. Choose another address or remove their items.");
        }

        if ($methodId === null) {
            return $quote->cheapest();
        }

        return $quote->option($methodId)
            ?? throw new CheckoutException("That delivery option is not available from {$quote->storeName} for this address. Please choose again.");
    }

    /**
     * @param  Collection<int, ShippingMethod>  $methods
     * @return array<int, ShippingOption>
     */
    private function optionsFor(Collection $methods, int $itemsCents, ?Region $region, Carbon $today): array
    {
        $options = [];

        foreach ($methods as $method) {
            $rule = $this->matchingRule($method, $region);

            if ($rule === false) {
                continue;
            }

            $base = Money::toCents($rule?->price ?? $method->price);
            $freeOver = $method->free_over !== null ? Money::toCents($method->free_over) : null;
            $free = $method->type->value === 'free_over' && $freeOver !== null && $itemsCents >= $freeOver;

            $options[] = $this->option($method, $free ? 0 : $base, $free, $freeOver, $today);
        }

        return $options;
    }

    /**
     * false = the method does not serve this region; null = it serves everywhere; a rule = the rule that matched.
     */
    private function matchingRule(ShippingMethod $method, ?Region $region): ShippingMethodRegion|false|null
    {
        if ($method->type->value === 'collection' || $method->regions->isEmpty()) {
            return null;
        }

        if ($region === null) {
            return false;
        }

        // Most specific first: a postal range beats a province beats a whole country.
        $rules = $method->regions->sortByDesc(fn (ShippingMethodRegion $r) => ($r->postal_from !== null ? 2 : 0) + ($r->province !== null ? 1 : 0));

        foreach ($rules as $rule) {
            if (strtoupper($rule->country) !== strtoupper($region->country)) {
                continue;
            }

            if ($rule->province !== null && mb_strtolower($rule->province) !== mb_strtolower((string) $region->province)) {
                continue;
            }

            if ($rule->postal_from !== null || $rule->postal_to !== null) {
                $postal = preg_replace('/\D/', '', (string) $region->postalCode);

                if ($postal === '' || ($rule->postal_from !== null && (int) $postal < (int) $rule->postal_from) || ($rule->postal_to !== null && (int) $postal > (int) $rule->postal_to)) {
                    continue;
                }
            }

            return $rule;
        }

        return false;
    }

    private function option(ShippingMethod $method, int $priceCents, bool $free, ?int $freeOver, Carbon $today): ShippingOption
    {
        $collection = $method->type->value === 'collection';
        $dispatch = BusinessDays::add($today, (int) $method->handling_days);
        $from = $collection ? $dispatch : BusinessDays::add($dispatch, (int) $method->window_min_days);
        $to = $collection ? $dispatch : BusinessDays::add($dispatch, max((int) $method->window_min_days, (int) $method->window_max_days));

        return new ShippingOption(
            $method->id, $method->name, $method->type->value, $priceCents, $free,
            (int) $method->handling_days, (int) $method->window_min_days, (int) $method->window_max_days,
            $from, $to, self::label($collection, $from, $to), $method->instructions, $collection ? $method->collection_address : null, $freeOver,
        );
    }

    private function arranged(): ShippingOption
    {
        return new ShippingOption(null, 'Delivery arranged with the seller', 'arranged', 0, false, 0, 0, 0, null, null, 'No delivery time was given by the seller.');
    }

    public static function label(bool $collection, Carbon $from, Carbon $to): string
    {
        $when = $from->equalTo($to)
            ? $from->format('j M')
            : ($from->format('M') === $to->format('M') ? $from->format('j').'–'.$to->format('j M') : $from->format('j M').' – '.$to->format('j M'));

        return ($collection ? 'Estimated ready for collection ' : 'Estimated delivery ').$when.' (an estimate from the seller\'s handling time, not a promise)';
    }
}
