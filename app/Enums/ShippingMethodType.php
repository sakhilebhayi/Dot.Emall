<?php

namespace App\Enums;

enum ShippingMethodType: string
{
    case Flat = 'flat';
    case FreeOver = 'free_over';
    case Collection = 'collection';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat rate',
            self::FreeOver => 'Free over a threshold',
            self::Collection => 'Collection',
        };
    }
}
