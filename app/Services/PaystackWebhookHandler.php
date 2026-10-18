<?php

namespace App\Services;

use App\Models\PaymentEvent;

/**
 * Acts on a Paystack webhook whose signature has ALREADY been verified by
 * the controller. Always finishes quietly: Paystack must get a 200 for an
 * event we understood but could not match, or it will retry for days.
 *
 * Handled: charge.success (marks the order paid) and the refund.* events
 * (refund.pending, refund.processing, refund.processed, refund.failed).
 * Everything else is acknowledged and ignored.
 */
class PaystackWebhookHandler
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly RefundService $refunds,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): string
    {
        $event = (string) $payload['event'];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($event === 'charge.success') {
            return $this->chargeSuccess($data);
        }

        if (str_starts_with($event, 'refund.')) {
            return $this->refunds->handleRefundEvent($event, $data);
        }

        return 'ignored';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chargeSuccess(array $data): string
    {
        if (($data['status'] ?? null) !== 'success') {
            return 'ignored';
        }

        $reference = $data['reference'] ?? null;
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        $id = $data['id'] ?? null;

        if (! is_string($reference) || ! (is_int($amount) || (is_string($amount) && ctype_digit($amount) && strlen($amount) < 16)) || ! is_string($currency) || ! is_scalar($id)) {
            PaymentEvent::create([
                'source' => 'webhook',
                'event' => 'charge.success',
                'reference' => is_string($reference) ? mb_substr($reference, 0, 100) : null,
                'outcome' => 'unmatched',
                'reason' => 'malformed_payload',
                'summary' => PaymentEvent::summarise($data),
            ]);

            return 'unmatched';
        }

        return $this->payments->recordSuccessfulCharge($reference, (int) $amount, $currency, (string) $id, $data, 'webhook');
    }
}
