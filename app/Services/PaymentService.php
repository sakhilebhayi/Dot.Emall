<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Takes a buyer's payment for an order through Paystack.
 *
 * Money rules: amounts are integer cents; an attempt records the amount it
 * asked Paystack to charge, and a confirmation only counts if its amount,
 * currency and reference match that attempt. Query strings are never
 * evidence: the callback asks Paystack (verify), the webhook is trusted only
 * after its HMAC signature has been checked.
 *
 * The callback and the webhook both end in recordSuccessfulCharge(), which
 * is the one place an order becomes paid. It runs in a transaction holding
 * the payment and order rows, and the unique indexes on payments.reference
 * and (provider, provider_transaction_id) back it up, so however the two
 * race a charge is applied exactly once.
 */
class PaymentService
{
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const UNMATCHED = 'unmatched';

    public const NEEDS_REVIEW = 'needs_review';

    public function __construct(
        private readonly PaystackClient $client,
        private readonly PaystackConfig $config,
        private readonly RefundService $refunds,
    ) {}

    /**
     * Begins (or resumes) payment of an order and returns the attempt whose
     * authorization_url the buyer should be sent to. If the order turns out
     * to be paid already (a charge the callback had not yet seen, or nothing
     * left to pay) the returned attempt is a successful one and the caller
     * should check the order instead of redirecting.
     *
     * @throws InvalidArgumentException when the order cannot be paid
     * @throws PaymentGatewayException when Paystack is unavailable or refused
     */
    public function startPayment(Order $order): Payment
    {
        $lock = Cache::lock('emall:pay-order:'.$order->id, 20);

        try {
            return $lock->block(5, fn () => $this->startLocked($order->id));
        } catch (LockTimeoutException) {
            throw PaymentGatewayException::unavailable();
        }
    }

    private function startLocked(int $orderId): Payment
    {
        $order = Order::query()->findOrFail($orderId);

        if (! $order->isPayable()) {
            throw new InvalidArgumentException($order->payment_status->isSettled()
                ? 'This order is already paid.'
                : 'This order can no longer be paid.');
        }

        $cents = $order->totalCents();

        if ($cents <= 0) {
            return $this->settleFreeOrder($order);
        }

        // Throws before anything is created when the key is missing or refused
        // (including PAYSTACK_CURRENCY disagreeing with SHOP_CURRENCY).
        $this->config->secretKey();

        // The money was priced in the ORDER's currency. If the gateway now
        // charges in another (SHOP_CURRENCY was changed after this order was
        // placed) the amount would be charged in the wrong money: refuse it.
        $currency = $order->currencyCode();

        if ($currency !== $this->config->currency()) {
            Log::warning('Payment refused: the order currency differs from the gateway currency', [
                'order_id' => $order->id, 'order_currency' => $currency, 'gateway_currency' => $this->config->currency(),
            ]);

            throw PaymentGatewayException::currencyMismatch($currency, $this->config->currency());
        }

        $ttl = now()->subMinutes($this->config->attemptTtlMinutes());

        $this->verifyOpenAttempts($order);

        $order->refresh();

        if (! $order->isPayable()) {
            return $order->payments()->where('status', PaymentAttemptStatus::Success->value)->latest('id')->first()
                ?? throw new InvalidArgumentException('This order can no longer be paid.');
        }

        $fresh = $order->payments()
            ->where('status', PaymentAttemptStatus::Pending->value)
            ->where('amount_cents', $cents)
            ->where('currency', $currency)
            ->where('created_at', '>', $ttl)
            ->whereNotNull('authorization_url')
            ->latest('id')
            ->first();

        if ($fresh !== null) {
            return $fresh;
        }

        $order->payments()->where('status', PaymentAttemptStatus::Pending->value)
            ->update(['status' => PaymentAttemptStatus::Abandoned->value]);

        return $this->initialize($order, $cents, $currency);
    }

    /**
     * Asks Paystack about every attempt still marked pending, and applies a
     * success or records a failure. Used before a new attempt is opened and
     * before an unpaid order is given up, so money already on its way is
     * never raced. Paystack being unreachable just leaves attempts as they
     * were.
     */
    public function verifyOpenAttempts(Order $order): void
    {
        if (! $this->config->isUsable()) {
            return;
        }

        foreach ($order->payments()->where('provider', 'paystack')->where('status', PaymentAttemptStatus::Pending->value)->orderByDesc('id')->get() as $open) {
            try {
                $verified = $this->client->verifyTransaction($open->reference);
            } catch (PaymentGatewayException) {
                continue;
            }

            if ($verified->isSuccessful() && $verified->reference === $open->reference) {
                $this->recordSuccessfulCharge($open->reference, $verified->amountCents, $verified->currency, $verified->transactionId, $verified->summary(), 'callback');
            } elseif ($verified->status === 'failed') {
                $this->markFailed($open);
            }
        }
    }

    private function initialize(Order $order, int $cents, string $currency): Payment
    {
        $order->loadMissing('user');
        $reference = 'EMALL-'.$order->id.'-'.Str::lower(Str::random(14));

        $transaction = $this->client->initializeTransaction(
            email: $order->user->email,
            amountCents: $cents,
            currency: $currency,
            reference: $reference,
            callbackUrl: route('orders.payment.callback', $order),
            metadata: ['order_id' => $order->id, 'order_reference' => $order->reference()],
        );

        return DB::transaction(function () use ($order, $cents, $currency, $reference, $transaction) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => 'paystack',
                'reference' => $reference,
                'amount_cents' => $cents,
                'currency' => $currency,
                'status' => PaymentAttemptStatus::Pending,
                'authorization_url' => $transaction->authorizationUrl,
            ]);

            Order::query()->whereKey($order->id)
                ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Failed->value])
                ->update(['payment_status' => PaymentStatus::Pending->value]);

            return $payment;
        });
    }

    /**
     * An order whose total is nothing (a 100% promo) has nothing to charge
     * and Paystack cannot charge zero: it is settled on the spot, with a
     * payment row so the books show why.
     */
    private function settleFreeOrder(Order $order): Payment
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $payment = Payment::create([
                'order_id' => $locked->id,
                'provider' => 'none',
                'reference' => 'EMALL-'.$locked->id.'-nocharge',
                'amount_cents' => 0,
                'currency' => $locked->currencyCode(),
                'status' => PaymentAttemptStatus::Success,
                'paid_at' => now(),
                'raw_summary' => ['note' => 'Nothing to charge: the order total is zero.'],
            ]);

            $locked->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
            OrderPaid::dispatch($locked);

            return $payment;
        });
    }

    /**
     * The buyer is back from Paystack. $reference (from the URL) only picks
     * WHICH of this order's own attempts to check; it is never believed.
     */
    public function confirmReturn(Order $order, ?string $reference): PaymentResult
    {
        if ($order->payment_status->isSettled()) {
            return new PaymentResult(PaymentResult::ALREADY_PAID);
        }

        if (! $this->config->isUsable()) {
            return new PaymentResult(PaymentResult::UNAVAILABLE);
        }

        $query = $order->payments()->where('provider', 'paystack');
        $attempt = $reference !== null && $reference !== ''
            ? $query->where('reference', $reference)->first()
            : $query->where('status', PaymentAttemptStatus::Pending->value)->latest('id')->first();

        if ($attempt === null) {
            return new PaymentResult(PaymentResult::UNKNOWN);
        }

        if ($attempt->status === PaymentAttemptStatus::Success) {
            return new PaymentResult(PaymentResult::ALREADY_PAID);
        }

        try {
            $verified = $this->client->verifyTransaction($attempt->reference);
        } catch (PaymentGatewayException) {
            return new PaymentResult(PaymentResult::UNAVAILABLE);
        }

        if ($verified->reference !== $attempt->reference) {
            $this->flagForReview($attempt, 'callback', 'reference_mismatch', $verified->amountCents, $verified->currency, $verified->summary());

            return new PaymentResult(PaymentResult::REVIEW);
        }

        if ($verified->isSuccessful()) {
            return match ($this->recordSuccessfulCharge($attempt->reference, $verified->amountCents, $verified->currency, $verified->transactionId, $verified->summary(), 'callback')) {
                self::APPLIED => new PaymentResult(PaymentResult::PAID),
                self::DUPLICATE => new PaymentResult(PaymentResult::ALREADY_PAID),
                default => new PaymentResult(PaymentResult::REVIEW),
            };
        }

        if ($verified->status === 'failed') {
            $this->markFailed($attempt);

            return new PaymentResult(PaymentResult::FAILED);
        }

        return new PaymentResult(PaymentResult::NOT_COMPLETED);
    }

    /**
     * The one place a charge is applied to an order. Idempotent: replaying
     * the same charge returns DUPLICATE and changes nothing.
     *
     * @param  array<string, mixed>  $summary  whitelisted Paystack fields to keep
     * @param  string  $source  'webhook' or 'callback'
     * @return string one of APPLIED, DUPLICATE, UNMATCHED, NEEDS_REVIEW
     */
    public function recordSuccessfulCharge(string $reference, int $amountCents, string $currency, string $transactionId, array $summary, string $source): string
    {
        try {
            $outcome = DB::transaction(fn () => $this->applyCharge($reference, $amountCents, strtoupper($currency), $transactionId, $summary, $source));
        } catch (UniqueConstraintViolationException) {
            // Another request claimed this Paystack transaction id first.
            $payment = Payment::query()->where('reference', $reference)->first();

            if ($payment !== null) {
                $this->flagForReview($payment, $source, 'transaction_already_applied', $amountCents, $currency, $summary);
            }

            return self::NEEDS_REVIEW;
        }

        if ($outcome === self::APPLIED) {
            $orderId = Payment::query()->where('reference', $reference)->value('order_id');

            if ($orderId !== null) {
                $this->refunds->submitPending($orderId);
            }
        }

        return $outcome;
    }

    private function applyCharge(string $reference, int $amountCents, string $currency, string $transactionId, array $summary, string $source): string
    {
        $payment = Payment::query()->where('reference', $reference)->lockForUpdate()->first();

        if ($payment === null || $payment->order_id === null) {
            $this->event($source, $reference, 'unmatched', 'unknown_reference', $amountCents, $currency, null, null, $summary);

            return self::UNMATCHED;
        }

        $order = Order::query()->lockForUpdate()->find($payment->order_id);

        if ($order === null) {
            $this->event($source, $reference, 'unmatched', 'order_missing', $amountCents, $currency, $payment, null, $summary);

            return self::UNMATCHED;
        }

        if ($payment->status === PaymentAttemptStatus::Success) {
            $this->event($source, $reference, 'duplicate', null, $amountCents, $currency, $payment, $order, $summary);

            return self::DUPLICATE;
        }

        if ($payment->amount_cents !== $amountCents) {
            $this->review($payment, $order, $source, 'amount_mismatch', 'unmatched', $amountCents, $currency, $summary);

            return self::UNMATCHED;
        }

        if ($payment->currency !== $currency) {
            $this->review($payment, $order, $source, 'currency_mismatch', 'unmatched', $amountCents, $currency, $summary);

            return self::UNMATCHED;
        }

        $clash = Payment::query()
            ->where('provider', $payment->provider)
            ->where('provider_transaction_id', $transactionId)
            ->where('id', '!=', $payment->id)
            ->exists();

        if ($clash) {
            $this->review($payment, $order, $source, 'transaction_already_applied', 'needs_review', $amountCents, $currency, $summary);

            return self::NEEDS_REVIEW;
        }

        // From here the money really did arrive; record that whatever we decide.
        $payment->forceFill([
            'provider_transaction_id' => $transactionId,
            'paid_at' => now(),
            'raw_summary' => PaymentEvent::summarise($summary),
        ]);

        $problem = match (true) {
            $order->status === OrderStatus::Cancelled => 'order_cancelled',
            $order->payment_status->isSettled() => 'order_already_paid',
            $order->totalCents() > $payment->amount_cents => 'underpaid',
            default => null,
        };

        if ($problem !== null) {
            $this->review($payment, $order, $source, $problem, 'needs_review', $amountCents, $currency, $summary);

            return self::NEEDS_REVIEW;
        }

        $payment->forceFill(['status' => PaymentAttemptStatus::Success])->save();
        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();

        // The buyer may have cancelled a line between starting and paying:
        // they paid for the old total, so queue the difference back.
        $this->refunds->reconcile($order);

        $this->event($source, $reference, 'applied', null, $amountCents, $currency, $payment, $order, $summary);

        OrderPaid::dispatch($order);

        return self::APPLIED;
    }

    /**
     * Puts a payment in front of an operator and records why.
     */
    private function review(Payment $payment, Order $order, string $source, string $reason, string $outcome, int $amountCents, string $currency, array $summary): void
    {
        $payment->forceFill(['status' => PaymentAttemptStatus::NeedsReview, 'reviewed_at' => null])->save();

        $this->event($source, $payment->reference, $outcome, $reason, $amountCents, $currency, $payment, $order, $summary);
    }

    /**
     * Same as review() for callers outside the apply transaction.
     */
    private function flagForReview(Payment $payment, string $source, string $reason, int $amountCents, string $currency, array $summary): void
    {
        DB::transaction(function () use ($payment, $source, $reason, $amountCents, $currency, $summary) {
            $locked = Payment::query()->lockForUpdate()->find($payment->id);

            if ($locked === null || $locked->status === PaymentAttemptStatus::Success) {
                return;
            }

            $this->review($locked, Order::query()->find($locked->order_id) ?? new Order, $source, $reason, 'unmatched', $amountCents, $currency, $summary);
        });
    }

    /**
     * Paystack says the attempt failed. The order goes back to "failed" (it
     * can be retried) unless another attempt is still open or it is settled.
     */
    public function markFailed(Payment $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            $payment = Payment::query()->lockForUpdate()->find($attempt->id);

            if ($payment === null || $payment->status !== PaymentAttemptStatus::Pending) {
                return;
            }

            $payment->forceFill(['status' => PaymentAttemptStatus::Failed])->save();

            $order = Order::query()->lockForUpdate()->find($payment->order_id);

            if ($order !== null
                && $order->payment_status === PaymentStatus::Pending
                && ! $order->payments()->where('status', PaymentAttemptStatus::Pending->value)->exists()) {
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            }
        });
    }

    private function event(string $source, ?string $reference, string $outcome, ?string $reason, ?int $amountCents, ?string $currency, ?Payment $payment, ?Order $order, array $summary): void
    {
        PaymentEvent::create([
            'source' => $source,
            'event' => 'charge.success',
            'reference' => $reference !== null ? mb_substr($reference, 0, 100) : null,
            'outcome' => $outcome,
            'reason' => $reason,
            'amount_cents' => $amountCents,
            'currency' => $currency !== null ? mb_substr($currency, 0, 3) : null,
            'payment_id' => $payment?->id,
            'order_id' => $order?->id,
            'summary' => PaymentEvent::summarise($summary),
        ]);
    }
}
