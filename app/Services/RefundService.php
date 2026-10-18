<?php

namespace App\Services;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Models\User;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Money owed back to a buyer when a PAID order shrinks (a line is cancelled
 * before shipping).
 *
 * Two steps, deliberately separate so a Paystack outage can never undo a
 * cancellation or lose the debt:
 *   1. reconcile() runs inside OrderLifecycle's transaction and writes a
 *      `refund_pending` row for whatever is owed: what was paid, less what
 *      the order is now worth, less refunds already recorded. Replays owe
 *      nothing new.
 *   2. submitPending() runs after the commit and asks Paystack to refund
 *      (POST /refund, partial amount). If that works the row becomes
 *      `refund_submitted`; if it fails the row stays `refund_pending` with
 *      the reason, and an operator sees "refund required" and can retry or
 *      refund by hand in the Paystack dashboard.
 *
 * Nothing is called `refunded` until Paystack's refund.processed event says
 * so (or an operator attests they did it by hand).
 */
class RefundService
{
    public function __construct(private readonly PaystackClient $client) {}

    /**
     * Records what is owed. Call inside the transaction that locked $order
     * and re-priced it.
     */
    public function reconcile(Order $order): ?Refund
    {
        if (! $order->requires_payment
            || ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            return null;
        }

        $payment = $order->payments()
            ->where('status', PaymentAttemptStatus::Success->value)
            ->where('provider', 'paystack')
            ->orderBy('id')
            ->first();

        if ($payment === null) {
            return null;
        }

        $owed = $payment->amount_cents - $order->totalCents();
        $recorded = (int) Refund::query()->where('payment_id', $payment->id)->sum('amount_cents');
        $delta = $owed - $recorded;

        if ($delta <= 0) {
            return null;
        }

        return Refund::create([
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'amount_cents' => $delta,
            'currency' => $payment->currency,
            'status' => RefundStatus::Pending,
            'reason' => "Order {$order->reference()}: items cancelled before shipping",
        ]);
    }

    /**
     * Asks Paystack for every refund of this order that is still only
     * `refund_pending`. Never throws: failures are recorded on the refund.
     */
    public function submitPending(int $orderId): void
    {
        Refund::query()
            ->where('order_id', $orderId)
            ->where('status', RefundStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->each(fn (Refund $refund) => $this->submit($refund));
    }

    public function submit(Refund $refund): Refund
    {
        $lock = Cache::lock('emall:refund:'.$refund->id, 30);

        if (! $lock->get()) {
            return $refund->refresh();
        }

        try {
            $refund->refresh();

            if ($refund->status !== RefundStatus::Pending) {
                return $refund;
            }

            $payment = Payment::query()->findOrFail($refund->payment_id);
            $order = $refund->order_id !== null ? Order::query()->find($refund->order_id) : null;
            $ref = $order?->reference() ?? 'an order';

            try {
                $created = $this->client->createRefund(
                    $payment->reference,
                    $refund->amount_cents,
                    $refund->currency,
                    "Refund for {$ref}: items cancelled before shipping",
                    "Dot.Emall refund #{$refund->id} for {$ref}",
                );
            } catch (PaymentGatewayException $e) {
                $refund->forceFill([
                    'attempts' => $refund->attempts + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 250),
                ])->save();

                return $refund;
            }

            $refund->forceFill([
                'status' => RefundStatus::Submitted,
                'provider_refund_id' => $created->refundId,
                'attempts' => $refund->attempts + 1,
                'last_error' => null,
                'submitted_at' => now(),
            ])->save();

            // Paystack may already report it as processed in the same reply.
            if ($created->status === 'processed') {
                $this->markProcessed($refund);
            }

            return $refund->refresh();
        } finally {
            $lock->release();
        }
    }

    /**
     * Applies a refund.* webhook. Returns 'applied', 'unmatched' or 'ignored'.
     *
     * @param  array<string, mixed>  $data
     */
    public function handleRefundEvent(string $event, array $data): string
    {
        $providerId = isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : null;

        $refund = $providerId !== null
            ? Refund::query()->where('provider_refund_id', $providerId)->first()
            : null;

        if ($refund === null) {
            PaymentEvent::create([
                'source' => 'webhook',
                'event' => mb_substr($event, 0, 64),
                'reference' => isset($data['transaction_reference']) && is_string($data['transaction_reference']) ? mb_substr($data['transaction_reference'], 0, 100) : null,
                'outcome' => 'unmatched',
                'reason' => 'unknown_refund',
                'summary' => PaymentEvent::summarise($data),
            ]);

            return 'unmatched';
        }

        match ($event) {
            'refund.processed' => $this->markProcessed($refund),
            'refund.failed' => $this->markFailed($refund),
            default => null,
        };

        return in_array($event, ['refund.processed', 'refund.failed'], true) ? 'applied' : 'ignored';
    }

    /**
     * An operator did the refund in the Paystack dashboard and says so.
     */
    public function markDoneByOperator(Refund $refund, User $operator, string $note): void
    {
        $this->markProcessed($refund, $operator, $note);
    }

    private function markProcessed(Refund $refund, ?User $operator = null, ?string $note = null): void
    {
        DB::transaction(function () use ($refund, $operator, $note) {
            $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status === RefundStatus::Refunded) {
                return;
            }

            $locked->forceFill([
                'status' => RefundStatus::Refunded,
                'processed_at' => now(),
                'last_error' => null,
                'resolved_by' => $operator?->id,
                'resolution_note' => $note,
            ])->save();

            $this->refreshOrderPaymentStatus($locked);
        });
    }

    private function markFailed(Refund $refund): void
    {
        $refund->refresh();

        if ($refund->status === RefundStatus::Refunded) {
            return;
        }

        $refund->forceFill([
            'status' => RefundStatus::Failed,
            'last_error' => 'Paystack reported that the refund failed. Refund it in the Paystack dashboard.',
        ])->save();
    }

    /**
     * `refunded` only when every paid cent has been confirmed back;
     * otherwise `partially_refunded`.
     */
    private function refreshOrderPaymentStatus(Refund $refund): void
    {
        if ($refund->order_id === null) {
            return;
        }

        $order = Order::query()->lockForUpdate()->find($refund->order_id);
        $payment = Payment::query()->find($refund->payment_id);

        if ($order === null || $payment === null || ! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            return;
        }

        $returned = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Refunded->value)
            ->sum('amount_cents');

        $order->forceFill([
            'payment_status' => $returned >= $payment->amount_cents ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
        ])->save();
    }
}
