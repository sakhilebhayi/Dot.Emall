<?php

namespace App\Enums;

/**
 * Where an ORDER stands on payment (orders.payment_status). Separate from
 * OrderStatus, which is fulfilment.
 *
 *  unpaid              nothing collected: no attempt yet, or the attempt lapsed
 *  pending             a Paystack attempt is open
 *  paid                Paystack confirmed the full amount
 *  failed              the last attempt was declined or failed
 *  refunded            Paystack confirmed every paid cent was refunded
 *  partially_refunded  some, not all, of the paid amount was refunded
 *
 * Orders placed while Paystack was not configured have requires_payment =
 * false and sit at `unpaid` forever; they are never auto-cancelled and
 * their fulfilment is not gated (the pre-Paystack behaviour).
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Money is held for this order: sellers may fulfil it.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::PartiallyRefunded, self::Refunded], true);
    }

    /**
     * The buyer may still be asked to pay.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Unpaid, self::Pending, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PartiallyRefunded => 'Partially refunded',
            default => ucfirst($this->value),
        };
    }
}
