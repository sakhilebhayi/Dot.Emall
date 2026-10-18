<?php

namespace App\Services;

/**
 * What happened when a buyer came back from Paystack, in terms the
 * controller can turn into one flash message. Never carries provider data.
 */
final readonly class PaymentResult
{
    public const PAID = 'paid';

    public const ALREADY_PAID = 'already_paid';

    public const NOT_COMPLETED = 'not_completed';

    public const FAILED = 'failed';

    public const REVIEW = 'review';

    public const UNAVAILABLE = 'unavailable';

    public const UNKNOWN = 'unknown';

    public function __construct(public string $outcome) {}

    public function isGood(): bool
    {
        return in_array($this->outcome, [self::PAID, self::ALREADY_PAID], true);
    }

    public function message(): string
    {
        return match ($this->outcome) {
            self::PAID => 'Payment received. Thank you: your order is now with the seller.',
            self::ALREADY_PAID => 'This order is already paid. Nothing more is needed.',
            self::FAILED, self::NOT_COMPLETED => 'Your payment was not completed. Press Pay now to try again.',
            self::REVIEW => 'We received a payment but could not match it to this order automatically. It has been flagged for review: please do not pay again.',
            self::UNAVAILABLE => 'We could not confirm your payment with Paystack just now. If you paid, this page will update shortly; otherwise press Pay now to try again.',
            default => 'We could not find that payment for this order.',
        };
    }
}
