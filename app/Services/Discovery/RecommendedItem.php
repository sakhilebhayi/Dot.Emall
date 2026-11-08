<?php

namespace App\Services\Discovery;

use App\Models\Product;

/** One recommended product with the real reason and number behind it. */
final class RecommendedItem
{
    public function __construct(
        public readonly Product $product,
        public readonly string $basis,
        public readonly ?int $support = null,
    ) {}
}
