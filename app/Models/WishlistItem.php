<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistItem extends Model
{
    protected $fillable = ['wishlist_id', 'product_id', 'variant_id', 'variant_key', 'note', 'price_when_added'];

    protected $casts = ['price_when_added' => 'decimal:2'];

    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
