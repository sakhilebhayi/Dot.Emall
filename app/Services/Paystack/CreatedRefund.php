<?php

namespace App\Services\Paystack;

final readonly class CreatedRefund
{
    public function __construct(
        public string $refundId,
        public string $status,
    ) {}
}
