<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Added (platform-loop pass, 2026-08-01) — the `product_images` table and
 * Product::images() relation already existed, but this model class did
 * not, so Product::images() would fatal with a "class not found" error
 * the moment it was ever called. No other code referenced ProductImage
 * directly, so the gap had not surfaced yet.
 */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
