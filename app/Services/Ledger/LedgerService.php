<?php

namespace App\Services\Ledger;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\Store;
use App\Models\StoreCommissionOverride;
use App\Models\User;
use App\Services\Notifications\OrderNotifier;
use App\Support\Shop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The seller ledger (D-2): what each seller group has earned, and when it is released. An internal record only; no payout
 * transfers exist.
 *
 *  - recordSale(): when an order is PAID, one `sale` entry per seller group: gross (the group's lines after promo, plus its
 *    delivery fee), the platform commission on the item value, and the net. Idempotent (idempotency_key sale:{group}).
 *  - syncGroup(): after any change (a line cancelled, a returned line refunded) the group's entries are brought back to what
 *    the order now says by appending a signed `reversal` entry for the difference. Amounts of existing rows never change.
 *  - scheduleRelease(): when the whole group has been delivered or collected, the sale becomes releasable 7 days later.
 *  - releaseDue(): the scheduled job. Moves held sales whose release_at has passed to released, unless a return on that group
 *    is still open. Safe to run twice, or concurrently: the update is guarded on status = held.
 *
 * Commission: config emall.commission.percent (default 0), or the per-store override. The percent in force is stored on the
 * entry, so a later change never rewrites an earlier sale.
 */
class LedgerService
{
    public function __construct(private readonly OrderNotifier $notifier) {}

    public function commissionPercent(?int $storeId): float
    {
        $override = $storeId !== null ? StoreCommissionOverride::query()->where('store_id', $storeId)->value('percent') : null;

        return round(max(0.0, min(100.0, (float) ($override ?? config('emall.commission.percent', 0)))), 2);
    }

    /**
     * @return int number of entries written
     */
    public function recordSale(Order $order): int
    {
        // Only money that has really arrived earns anything.
        if (! $order->payment_status->isSettled()) {
            return 0;
        }

        $written = 0;

        foreach ($order->groups()->get() as $group) {
            $lines = $this->liveLines($group);

            if ($lines->isEmpty()) {
                continue;
            }

            $itemsNet = $lines->sum(fn (OrderItem $l) => $l->netCents());
            $gross = $itemsNet + $group->delivery_cents;

            if ($gross <= 0) {
                continue;
            }

            $percent = $this->commissionPercent($group->store_id);
            $commission = (int) round($itemsNet * $percent / 100);

            try {
                DB::transaction(fn () => LedgerEntry::create([
                    'order_group_id' => $group->id,
                    'order_id' => $order->id,
                    'seller_id' => $group->seller_id,
                    'store_id' => $group->store_id,
                    'kind' => 'sale',
                    'status' => 'held',
                    'currency' => $order->currencyCode(),
                    'gross_cents' => $gross,
                    'commission_cents' => $commission,
                    'net_cents' => $gross - $commission,
                    'commission_percent' => $percent,
                    'reason' => "Order {$order->reference()} paid",
                    'idempotency_key' => 'sale:'.$group->id,
                ]));
                $written++;
            } catch (UniqueConstraintViolationException) {
                // Already recorded: a replay.
            }
        }

        return $written;
    }

    /**
     * Brings a group's ledger back in line with its order. Call under the order lock, after OrderPricing::apply().
     */
    public function syncGroup(?int $groupId, string $reason, ?int $orderItemId = null): ?LedgerEntry
    {
        if ($groupId === null) {
            return null;
        }

        $sale = LedgerEntry::query()->where('order_group_id', $groupId)->where('kind', 'sale')->first();

        if ($sale === null) {
            return null;
        }

        $group = OrderGroup::query()->findOrFail($groupId);
        $entries = LedgerEntry::query()->where('order_group_id', $groupId)->get(['gross_cents', 'commission_cents', 'net_cents']);

        $lines = $this->liveLines($group);
        $itemsNet = $lines->sum(fn (OrderItem $l) => $l->netCents());

        // The seller keeps the delivery fee of a parcel that was delivered, even when its only line is then returned and
        // refunded (D-17: a return does not refund delivery). Only a parcel with every line cancelled has no delivery to earn.
        $delivered = OrderItem::query()->where('order_group_id', $groupId)->where('status', '!=', OrderStatus::Cancelled->value)->exists();
        $targetGross = $itemsNet + ($delivered ? $group->delivery_cents : 0);
        $targetCommission = (int) round($itemsNet * (float) $sale->commission_percent / 100);

        $dGross = $targetGross - (int) $entries->sum('gross_cents');
        $dCommission = $targetCommission - (int) $entries->sum('commission_cents');

        $entry = null;

        if ($dGross !== 0 || $dCommission !== 0) {
            $count = LedgerEntry::query()->where('order_group_id', $groupId)->where('kind', 'reversal')->count();

            try {
                $entry = LedgerEntry::create([
                    'order_group_id' => $groupId,
                    'order_id' => $sale->order_id,
                    'seller_id' => $sale->seller_id,
                    'store_id' => $sale->store_id,
                    'order_item_id' => $orderItemId,
                    'reverses_entry_id' => $sale->id,
                    'kind' => 'reversal',
                    'status' => 'reversed',
                    'currency' => $sale->currency,
                    'gross_cents' => $dGross,
                    'commission_cents' => $dCommission,
                    'net_cents' => $dGross - $dCommission,
                    'commission_percent' => $sale->commission_percent,
                    'reason' => $reason,
                    'idempotency_key' => 'rev:'.$groupId.':'.($count + 1),
                ]);
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        }

        if ($targetGross === 0 && $sale->status === 'held') {
            LedgerEntry::query()->whereKey($sale->id)->where('status', 'held')->update(['status' => 'reversed']);
        }

        return $entry;
    }

    /**
     * The whole group has reached the buyer: its sale entry can be released after the waiting period.
     */
    public function scheduleRelease(OrderGroup $group, ?Carbon $deliveredAt = null): void
    {
        $at = ($deliveredAt ?? now())->copy()->addDays((int) config('emall.commission.release_after_days', 7));

        LedgerEntry::query()
            ->where('order_group_id', $group->id)->where('kind', 'sale')->where('status', 'held')->whereNull('release_at')
            ->update(['release_at' => $at]);
    }

    /**
     * @return array{released: int, skipped_open_returns: int, net_cents: int}
     */
    public function releaseDue(?Carbon $now = null): array
    {
        $now ??= now();
        $released = 0;
        $skipped = 0;
        $net = 0;
        $bySeller = [];

        LedgerEntry::query()
            ->where('kind', 'sale')->where('status', 'held')->whereNotNull('release_at')->where('release_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($entries) use ($now, &$released, &$skipped, &$net, &$bySeller) {
                foreach ($entries as $entry) {
                    if ($this->hasOpenReturn($entry->order_group_id)) {
                        $skipped++;

                        continue;
                    }

                    $effective = (int) LedgerEntry::query()->where('order_group_id', $entry->order_group_id)->sum('net_cents');

                    $updated = LedgerEntry::query()->whereKey($entry->id)->where('status', 'held')
                        ->update(['status' => 'released', 'released_at' => $now]);

                    if ($updated === 1) {
                        $released++;
                        $net += $effective;
                        $bySeller[$entry->seller_id][$entry->order_id] = ($bySeller[$entry->seller_id][$entry->order_id] ?? 0) + $effective;
                    }
                }
            });

        foreach ($bySeller as $sellerId => $orders) {
            foreach ($orders as $orderId => $cents) {
                if ($cents > 0 && $sellerId) {
                    $this->notifier->ledgerReleased((int) $sellerId, (int) $orderId, $cents);
                }
            }
        }

        return ['released' => $released, 'skipped_open_returns' => $skipped, 'net_cents' => $net];
    }

    /**
     * A seller's position, in cents per currency: held (earned, waiting out the return window), released (available),
     * with reversals counted against the sale they belong to.
     *
     * @return array<string, array{held: int, released: int, commission: int}>
     */
    public function balance(User|int $seller): array
    {
        $sellerId = $seller instanceof User ? $seller->id : $seller;
        $out = [];

        $sales = LedgerEntry::query()->where('seller_id', $sellerId)->where('kind', 'sale')
            ->selectRaw('currency, status, sum(net_cents) as net, sum(commission_cents) as commission')
            ->groupBy('currency', 'status')->get();

        foreach ($sales as $row) {
            $bucket = $row->status === 'released' ? 'released' : 'held';
            $out[$row->currency][$bucket] = ($out[$row->currency][$bucket] ?? 0) + (int) $row->net;
            $out[$row->currency]['commission'] = ($out[$row->currency]['commission'] ?? 0) + (int) $row->commission;
        }

        $reversals = LedgerEntry::query()->where('ledger_entries.seller_id', $sellerId)->where('ledger_entries.kind', 'reversal')
            ->join('ledger_entries as sale', 'sale.id', '=', 'ledger_entries.reverses_entry_id')
            ->selectRaw('ledger_entries.currency as currency, sale.status as sale_status, sum(ledger_entries.net_cents) as net, sum(ledger_entries.commission_cents) as commission')
            ->groupBy('ledger_entries.currency', 'sale.status')->get();

        foreach ($reversals as $row) {
            $bucket = $row->sale_status === 'released' ? 'released' : 'held';
            $out[$row->currency][$bucket] = ($out[$row->currency][$bucket] ?? 0) + (int) $row->net;
            $out[$row->currency]['commission'] = ($out[$row->currency]['commission'] ?? 0) + (int) $row->commission;
        }

        foreach ($out as $currency => $row) {
            $out[$currency] = ['held' => $row['held'] ?? 0, 'released' => $row['released'] ?? 0, 'commission' => $row['commission'] ?? 0];
        }

        return $out;
    }

    private function hasOpenReturn(int $groupId): bool
    {
        return ReturnRequest::query()
            ->whereIn('order_item_id', OrderItem::query()->where('order_group_id', $groupId)->select('id'))
            ->whereIn('status', collect(ReturnStatus::cases())->filter->isOpen()->map->value->all())
            ->exists();
    }

    /**
     * @return Collection<int, OrderItem>
     */
    private function liveLines(OrderGroup $group)
    {
        return OrderItem::query()->where('order_group_id', $group->id)
            ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])->get();
    }

    public static function money(int $cents, string $currency): string
    {
        return Shop::money($cents, $currency);
    }

    public function overrideFor(Store $store): ?StoreCommissionOverride
    {
        return $store->commissionOverride;
    }
}
