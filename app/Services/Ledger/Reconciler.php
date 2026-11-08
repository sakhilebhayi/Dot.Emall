<?php

namespace App\Services\Ledger;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Money;

/**
 * Orders vs payments vs refunds vs ledger. Read-only: it finds disagreements, it never repairs them.
 *
 * For every order that has been paid:
 *   1. the money that arrived (successful payments) less the refunds owed for CANCELLATIONS equals the order total;
 *   2. the order total equals items - discount + delivery;
 *   3. the delivery fees on the seller groups add up to the order's delivery;
 *   4. the ledger, summed over the order's groups (sales and reversals), equals the order total less the value of lines
 *      refunded after delivery.
 * And across the system: a successful payment must belong to an order that is paid, a refund must have a payment.
 */
class Reconciler
{
    /**
     * @return array{checked: int, mismatches: array<int, array{order_id: ?int, check: string, expected: int, actual: int, detail: string}>}
     */
    public function run(?int $sinceDays = null): array
    {
        $mismatches = [];
        $checked = 0;

        Order::query()
            ->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value])
            ->when($sinceDays !== null, fn ($q) => $q->where('paid_at', '>=', now()->subDays($sinceDays)))
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$mismatches, &$checked) {
                foreach ($orders as $order) {
                    $checked++;
                    array_push($mismatches, ...$this->checkOrder($order));
                }
            });

        Payment::query()->where('status', PaymentAttemptStatus::Success->value)->where('amount_cents', '>', 0)
            ->whereDoesntHave('order', fn ($q) => $q->whereIn('payment_status', [PaymentStatus::Paid->value, PaymentStatus::PartiallyRefunded->value, PaymentStatus::Refunded->value]))
            ->get()->each(function (Payment $payment) use (&$mismatches) {
                $mismatches[] = $this->miss($payment->order_id, 'payment_without_paid_order', 0, (int) $payment->amount_cents, "Successful payment {$payment->reference} belongs to an order that is not paid.");
            });

        Refund::query()->whereDoesntHave('payment')->get()->each(function (Refund $refund) use (&$mismatches) {
            $mismatches[] = $this->miss($refund->order_id, 'refund_without_payment', 0, (int) $refund->amount_cents, "Refund #{$refund->id} has no payment.");
        });

        return ['checked' => $checked, 'mismatches' => $mismatches];
    }

    /**
     * @return array<int, array{order_id: ?int, check: string, expected: int, actual: int, detail: string}>
     */
    private function checkOrder(Order $order): array
    {
        $out = [];

        $paid = (int) Payment::query()->where('order_id', $order->id)->where('status', PaymentAttemptStatus::Success->value)->sum('amount_cents');
        $cancellationRefunds = (int) Refund::query()->where('order_id', $order->id)->where('kind', '!=', 'return')
            ->where('status', '!=', RefundStatus::Failed->value)->sum('amount_cents');
        $returnRefunds = (int) Refund::query()->where('order_id', $order->id)->where('kind', 'return')
            ->where('status', '!=', RefundStatus::Failed->value)->sum('amount_cents');

        $total = $order->totalCents();

        if ($paid - $cancellationRefunds !== $total) {
            $out[] = $this->miss($order->id, 'paid_less_refunds_vs_total', $total, $paid - $cancellationRefunds, "Order {$order->reference()}: paid {$paid} less cancellation refunds {$cancellationRefunds} should equal the order total.");
        }

        $lines = OrderItem::query()->where('order_id', $order->id)->where('status', '!=', OrderStatus::Cancelled->value)->get();
        $itemsNet = $lines->sum(fn (OrderItem $l) => Money::toCents($l->total));
        $discount = Money::toCents($order->discount_amount);
        $delivery = $order->deliveryCents();

        if ($itemsNet - $discount + $delivery !== $total) {
            $out[] = $this->miss($order->id, 'lines_vs_total', $total, $itemsNet - $discount + $delivery, "Order {$order->reference()}: live lines - discount + delivery should equal the total.");
        }

        $groups = $order->groups()->get();

        if ($groups->isNotEmpty()) {
            $groupDelivery = $groups->filter(fn ($g) => $lines->contains('order_group_id', $g->id))->sum('delivery_cents');

            if ($groupDelivery !== $delivery) {
                $out[] = $this->miss($order->id, 'group_delivery_vs_order', $delivery, (int) $groupDelivery, "Order {$order->reference()}: parcels' delivery fees should add up to the order's delivery.");
            }

            if (LedgerEntry::query()->where('order_id', $order->id)->exists() || $paid > 0) {
                $ledger = (int) LedgerEntry::query()->where('order_id', $order->id)->sum('gross_cents');
                $returned = $lines->where('status', OrderStatus::Refunded)->sum(fn (OrderItem $l) => $l->netCents());
                $expected = $total - $returned;

                if ($ledger !== $expected && $total > 0) {
                    $out[] = $this->miss($order->id, 'ledger_vs_order', $expected, $ledger, "Order {$order->reference()}: the seller ledger should equal the total less lines refunded after delivery.");
                }
            }
        }

        if ($returnRefunds > 0) {
            $returned = $lines->where('status', OrderStatus::Refunded)->sum(fn (OrderItem $l) => $l->netCents());

            if ($returnRefunds !== $returned) {
                $out[] = $this->miss($order->id, 'return_refunds_vs_lines', $returned, $returnRefunds, "Order {$order->reference()}: return refunds should equal the value of refunded lines.");
            }
        }

        return $out;
    }

    /**
     * @return array{order_id: ?int, check: string, expected: int, actual: int, detail: string}
     */
    private function miss(?int $orderId, string $check, int $expected, int $actual, string $detail): array
    {
        return ['order_id' => $orderId, 'check' => $check, 'expected' => $expected, 'actual' => $actual, 'detail' => $detail];
    }
}
