<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'store_id', 'category_id', 'name', 'slug', 'description',
        'price', 'compare_price', 'stock', 'sku', 'is_active',
        'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'is_active' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function averageRating(): float
    {
        return round((float) $this->reviews()->avg('rating'), 1);
    }

    /**
     * Products visible in the buyer-facing marketplace (platform-loop pass):
     * the product itself must be active AND its parent store must be
     * active AND the listing must have passed moderation review
     * (status === 'approved') -- a product left active under a
     * deactivated store, or still pending/rejected, should not be
     * independently browsable/purchasable.
     */
    public function scopeBrowsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', 'approved')
            ->whereHas('store', fn (Builder $q) => $q->where('is_active', true));
    }
}
