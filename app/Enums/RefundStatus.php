<?php

namespace App\Enums;

/**
 * A refund owed to a buyer. Nothing here means money went back until
 * `refunded`, which is set only by Paystack's refund.processed event or by an
 * operator who did it by hand and says so.
 *
 *  refund_pending    owed; not yet accepted by Paystack (API not called, or the
 *                    call failed). An operator must retry or refund by hand.
 *  refund_submitted  Paystack accepted the request and is processing it
 *  refunded          Paystack reported it processed (or an operator marked it done)
 *  refund_failed     Paystack reported the refund failed; do it by hand
 */
enum RefundStatus: string
{
    case Pending = 'refund_pending';
    case Submitted = 'refund_submitted';
    case Refunded = 'refunded';
    case Failed = 'refund_failed';

    public function needsOperator(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
