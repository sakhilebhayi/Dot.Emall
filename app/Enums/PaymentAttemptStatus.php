<?php

namespace App\Enums;

/**
 * One Paystack attempt (a row in `payments`).
 *
 *  pending       initialised, no result yet
 *  success       Paystack confirmed the charge and it was applied to the order
 *  failed        Paystack reported the attempt failed
 *  abandoned     replaced by a newer attempt, or its order expired
 *  needs_review  money arrived (or something did not add up) and a human must
 *                decide: a payment for a cancelled order, a second payment for
 *                an order already paid, an amount that does not match
 */
enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
    case NeedsReview = 'needs_review';
}
