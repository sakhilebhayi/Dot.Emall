<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'product_id', 'variant_id', 'quantity'];

    protected static function booted(): void
    {
        // A line always names the exact variant: callers that only know the product get its default variant.
        static::creating(function (CartItem $item): void {
            if ($item->variant_id === null && $item->product_id !== null) {
                $item->variant_id = ProductVariant::query()
                    ->where('product_id', $item->product_id)
                    ->orderByDesc('is_default')->orderBy('position')->orderBy('id')
                    ->value('id');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
