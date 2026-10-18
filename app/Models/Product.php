<?php

namespace App\Models;

use App\Services\ProductImages;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deleting a listing is an archive (soft delete): the row stays so order
 * history, reviews and snapshots that reference it keep resolving, and it
 * disappears from every storefront query. Any cart still holding it is
 * emptied of it at the moment it is archived.
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::deleted(function (Product $product): void {
            // A cart line for a product nobody can buy any more would only
            // break the cart page; the global user scope is bypassed because
            // this runs on behalf of the seller, not the cart's owner.
            CartItem::withoutGlobalScopes()->where('product_id', $product->id)->delete();
        });

        // Listing photos go with the listing, files included. Runs BEFORE the
        // delete: on a hard delete the database's own ON DELETE CASCADE would
        // remove the rows first and leave their files orphaned on the disk.
        // (Order history keeps its own snapshot and never needed the photos.)
        static::deleting(function (Product $product): void {
            app(ProductImages::class)->purge($product);
        });
    }

    protected $fillable = [
        'store_id', 'category_id', 'name', 'slug', 'description',
        'price', 'compare_price', 'stock', 'sku', 'is_active',
        'status', 'rejected_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'rating_average' => 'decimal:2',
        'rating_count' => 'integer',
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
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
