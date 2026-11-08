<?php

namespace App\Services\Discovery;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What counts as a real purchase when a number is shown to a shopper (units sold, bought together, trending,
 * reorder). One definition, in one place, so every figure agrees:
 *
 *   an order LINE counts when it is not cancelled or returned-and-refunded and either its order's payment is confirmed
 *   (paid or partially refunded) or the order never required payment (placed before the gateway was configured) and
 *   the line is COMPLETED (delivered, or collected: the state machine's own OrderStatus::completedValues, D-51).
 *   Pending, unpaid, failed, cancelled and fully refunded orders never count, and a line whose return was refunded
 *   is not a sale to rank or recommend by.
 */
class CountedOrders
{
    /** Order lines that count, joined to their orders: aliases oi (order_items) and o (orders). */
    public function lines(?CarbonInterface $since = null): Builder
    {
        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotIn('oi.status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->where(function ($q) {
                $q->whereIn('o.payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                    ->orWhere(function ($q) {
                        $q->where('o.requires_payment', false)
                            ->whereIn('oi.status', OrderStatus::completedValues())
                            ->where('o.payment_status', '!=', PaymentStatus::Refunded->value);
                    });
            });

        if ($since !== null) {
            $query->where('o.created_at', '>=', $since);
        }

        return $query;
    }

    /** Orders that count (at least one counted line): alias o. */
    public function orders(?CarbonInterface $since = null, ?CarbonInterface $until = null): Builder
    {
        $query = DB::table('orders as o')->whereExists(function ($sub) {
            $sub->select(DB::raw('1'))->from('order_items as oi')
                ->whereColumn('oi.order_id', 'o.id')
                ->where('oi.status', '!=', OrderStatus::Cancelled->value)
                ->where(function ($q) {
                    $q->whereIn('o.payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value])
                        ->orWhere(function ($q) {
                            $q->where('o.requires_payment', false)
                                ->whereIn('oi.status', OrderStatus::completedValues())
                                ->where('o.payment_status', '!=', PaymentStatus::Refunded->value);
                        });
                });
        });

        if ($since !== null) {
            $query->where('o.created_at', '>=', $since);
        }

        if ($until !== null) {
            $query->where('o.created_at', '<', $until);
        }

        return $query;
    }

    /**
     * Units sold per product across counted lines.
     *
     * @return array<int, int> product id => units
     */
    public function unitsSoldByProduct(?CarbonInterface $since = null): array
    {
        return $this->lines($since)->whereNotNull('oi.product_id')
            ->groupBy('oi.product_id')->selectRaw('oi.product_id as product_id, sum(oi.quantity) as units')
            ->pluck('units', 'product_id')->map(fn ($units) => (int) $units)->all();
    }
}
