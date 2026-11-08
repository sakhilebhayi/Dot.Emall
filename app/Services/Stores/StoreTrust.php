<?php

namespace App\Services\Stores;

use App\Enums\OrderStatus;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Services\Shipping\BusinessDays;
use Illuminate\Support\Carbon;

/**
 * The seller trust ladder, built from REAL data only (plan 11). Each rung says where it comes from, and every number carries
 * the sample behind it; below config emall.seller.trust_min_sample the number is withheld ("not enough orders yet") rather than
 * shown as a percentage of nothing.
 *
 * Rungs and their states:
 *   yes      a fact the platform recorded (signed in through InfoDot, InfoDot company linked)
 *   claimed  something the seller typed (registration number, VAT number): shown, labelled claimed, never checked here
 *   no       the fact is absent
 * There is NO "verified seller" state unless InfoDot's own data says the linked company is verified (teams.infodot_verified_at,
 * written only from InfoDot's sign-in payload).
 */
class StoreTrust
{
    private const LOOKBACK_DAYS = 180;

    private const MAX_GROUPS = 500;

    /**
     * @return array{ladder: array<int, array{key: string, label: string, state: string, source: string, detail: ?string}>, metrics: array<string, array{value: int|float|null, sample: int, note: ?string, unit: string}>, verified: bool, months_on_emall: int}
     */
    public static function for(Store $store): array
    {
        return (new self)->build($store);
    }

    public function build(Store $store): array
    {
        $store->loadMissing('owner:id,infodot_signed_in_at', 'team:id,infodot_team_uuid,infodot_verified_at');

        $min = (int) config('emall.seller.trust_min_sample', 5);
        $team = $store->team;
        $linked = $team !== null && filled($team->infodot_team_uuid);
        $verified = $linked && $team->infodot_verified_at !== null;

        $ladder = [
            ['key' => 'infodot_sign_in', 'label' => 'Signs in through InfoDot', 'state' => $store->owner?->infodot_signed_in_at !== null ? 'yes' : 'no', 'source' => 'InfoDot', 'detail' => null],
            ['key' => 'infodot_company', 'label' => 'InfoDot company linked', 'state' => $linked ? 'yes' : 'no', 'source' => 'InfoDot', 'detail' => $linked ? $team->name ?? null : null],
        ];

        if ($verified) {
            $ladder[] = ['key' => 'infodot_verified', 'label' => 'Company verified by InfoDot', 'state' => 'yes', 'source' => 'InfoDot', 'detail' => null];
        }

        $ladder[] = ['key' => 'registration_number', 'label' => 'Company registration number', 'state' => filled($store->registration_number) ? 'claimed' : 'no', 'source' => 'Seller', 'detail' => filled($store->registration_number) ? $store->registration_number.' (claimed by the seller, not checked)' : null];
        $ladder[] = ['key' => 'vat_number', 'label' => 'VAT number', 'state' => $store->vat_registered && filled($store->vat_number) ? 'claimed' : 'no', 'source' => 'Seller', 'detail' => $store->vat_registered && filled($store->vat_number) ? $store->vat_number.' (claimed by the seller, not checked)' : null];

        [$onTime, $due, $hours] = $this->shippingFigures($store);
        [$returns, $completed] = $this->returnFigures($store);
        [$reviewCount, $reviewAverage] = $this->reviewFigures($store);

        $metrics = [
            'fulfilment_rate' => $this->metric($due >= $min ? round($onTime / $due * 100, 1) : null, $due, 'percent', 'shipped within the handling time they promised'),
            'median_hours_to_ship' => $this->metric(count($hours) >= $min ? $this->median($hours) : null, count($hours), 'hours', 'from payment to shipped'),
            'return_rate' => $this->metric($completed >= $min ? round($returns / $completed * 100, 1) : null, $completed, 'percent', 'of delivered items had a return requested'),
            'review_count' => ['value' => $reviewCount, 'sample' => $reviewCount, 'note' => null, 'unit' => 'reviews'],
            'review_average' => ['value' => $reviewCount > 0 ? $reviewAverage : null, 'sample' => $reviewCount, 'note' => $reviewCount === 0 ? 'No reviews yet.' : null, 'unit' => 'stars'],
        ];

        return [
            'ladder' => $ladder,
            'metrics' => $metrics,
            'verified' => $verified,
            'months_on_emall' => (int) $store->created_at?->diffInMonths(now()),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: array<int, float>} on-time count, groups that were due, hours-to-ship samples
     */
    private function shippingFigures(Store $store): array
    {
        $groups = OrderGroup::query()
            ->join('orders', 'orders.id', '=', 'order_groups.order_id')
            ->where('order_groups.store_id', $store->id)
            ->where('order_groups.method_type', '!=', 'collection')
            ->where('order_groups.status', '!=', OrderStatus::Cancelled->value)
            ->where('orders.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where(fn ($q) => $q->where('orders.requires_payment', false)->orWhereNotNull('orders.paid_at'))
            ->orderByDesc('order_groups.id')->limit(self::MAX_GROUPS)
            ->get(['order_groups.id', 'order_groups.handling_days', 'order_groups.shipped_at', 'orders.paid_at', 'orders.created_at as order_created_at']);

        $onTime = 0;
        $due = 0;
        $hours = [];

        foreach ($groups as $group) {
            $base = Carbon::parse($group->paid_at ?? $group->order_created_at);
            $deadline = BusinessDays::add($base, (int) $group->handling_days)->endOfDay();
            $shipped = $group->shipped_at ? Carbon::parse($group->shipped_at) : null;

            if ($shipped !== null) {
                $hours[] = max(0, $base->diffInMinutes($shipped, false)) / 60;
            }

            if ($shipped === null && $deadline->isFuture()) {
                continue; // not due yet: neither a hit nor a miss
            }

            $due++;

            if ($shipped !== null && $shipped->lte($deadline)) {
                $onTime++;
            }
        }

        return [$onTime, $due, $hours];
    }

    /**
     * @return array{0: int, 1: int} returns requested, items delivered/collected/refunded
     */
    private function returnFigures(Store $store): array
    {
        $completed = OrderItem::query()->where('store_id', $store->id)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Collected->value, OrderStatus::Refunded->value])->count();

        $returns = ReturnRequest::query()->where('seller_id', $store->owner_id)
            ->whereIn('order_item_id', OrderItem::query()->where('store_id', $store->id)->select('id'))->count();

        return [$returns, $completed];
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function reviewFigures(Store $store): array
    {
        $row = Product::query()->withTrashed()->where('store_id', $store->id)->where('rating_count', '>', 0)
            ->selectRaw('sum(rating_count) as n, sum(rating_average * rating_count) as stars')->first();

        $n = (int) ($row->n ?? 0);

        return [$n, $n > 0 ? round((float) $row->stars / $n, 2) : 0.0];
    }

    /**
     * @param  array<int, float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }

    /**
     * @return array{value: int|float|null, sample: int, note: ?string, unit: string}
     */
    private function metric(int|float|null $value, int $sample, string $unit, string $means): array
    {
        $min = (int) config('emall.seller.trust_min_sample', 5);

        return [
            'value' => $value,
            'sample' => $sample,
            'note' => $value === null ? "Not enough orders yet to show this (needs {$min}; has {$sample})." : "Based on {$sample} ".($unit === 'hours' ? 'parcels' : 'orders').": {$means}.",
            'unit' => $unit,
        ];
    }
}
