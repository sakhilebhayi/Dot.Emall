<?php

namespace App\Services\Catalogue;

use App\Models\ProductVariant;
use App\Services\CheckoutException;

class OutOfStock extends CheckoutException
{
    public function __construct(public readonly ProductVariant $variant, public readonly int $wanted)
    {
        $name = $variant->product?->name ?? 'An item in your cart';
        $label = $variant->label();
        $name = $label ? "{$name} ({$label})" : $name;
        $left = max(0, (int) ProductVariant::query()->whereKey($variant->id)->value('stock'));

        parent::__construct($left === 0
            ? "\"{$name}\" is out of stock now. Remove it from your cart to continue."
            : "Only {$left} of \"{$name}\" left, and you have {$wanted} in your cart. Lower the quantity to continue.");
    }
}
