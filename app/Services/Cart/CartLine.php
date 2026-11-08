<?php

namespace App\Services\Cart;

use App\Models\Product;
use App\Models\ProductVariant;

/** One line of a cart, whoever owns it (an account's cart row or a guest's session entry). */
final class CartLine
{
    public function __construct(
        public readonly ProductVariant $variant,
        public readonly Product $product,
        public readonly int $quantity,
        public readonly ?int $cartItemId = null,
    ) {}

    public function unitCents(): int
    {
        return $this->variant->priceCents();
    }

    public function totalCents(): int
    {
        return $this->unitCents() * $this->quantity;
    }

    public function storeId(): int
    {
        return (int) $this->product->store_id;
    }

    /** Why this line cannot be bought as it stands, or null. Re-checked, authoritatively, at checkout. */
    public function problem(): ?string
    {
        if (! CartManager::isBuyable($this->variant, $this->product)) {
            return 'No longer available. Remove it to continue.';
        }

        if ($this->quantity > $this->variant->stock) {
            return $this->variant->stock === 0
                ? 'Out of stock now. Remove it to continue.'
                : "Only {$this->variant->stock} left. Lower the quantity to continue.";
        }

        return null;
    }
}
