<?php

namespace App\Enums;

enum StoreStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            default => ucfirst($this->value),
        };
    }
}
