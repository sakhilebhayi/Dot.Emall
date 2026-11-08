<?php

namespace App\Enums;

/**
 * requested  buyer asked; the seller has respond_by to answer
 * approved   seller (or operator) agreed; waiting for the goods to come back
 * declined   seller said no, with a stated reason; the buyer may escalate
 * received   seller confirmed the goods are back (refund follows)
 * refunded   the refund was recorded (money follows through the refund record)
 * escalated  with the operator: the seller did not answer in time, or the buyer disputed a decline
 * closed     operator upheld a decline, or the buyer withdrew
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Declined = 'declined';
    case Received = 'received';
    case Refunded = 'refunded';
    case Escalated = 'escalated';
    case Closed = 'closed';

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved, self::Received, self::Escalated], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
