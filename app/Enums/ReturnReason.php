<?php

namespace App\Enums;

/**
 * D-6: faulty / not as described / wrong item: the seller pays the return. Changed mind: the buyer pays.
 */
enum ReturnReason: string
{
    case Faulty = 'faulty';
    case NotAsDescribed = 'not_as_described';
    case WrongItem = 'wrong_item';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function sellerPaysReturn(): bool
    {
        return in_array($this, [self::Faulty, self::NotAsDescribed, self::WrongItem], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Faulty => 'Faulty or damaged',
            self::NotAsDescribed => 'Not as described',
            self::WrongItem => 'Wrong item sent',
            self::ChangedMind => 'Changed my mind',
            self::Other => 'Something else',
        };
    }
}
