<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo of a listing, stored on the public disk under a random name.
 * sort_order 0 is the cover. See App\Services\ProductImages.
 */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'thumb_path', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * The picture for a card or a thumbnail (at most 640 px on its long edge). A photo uploaded before card pictures existed has none
     * yet, so it falls back to the full-size file until `emall:images-thumbnails` has made one.
     */
    public function cardUrl(): string
    {
        return Storage::disk('public')->url($this->thumb_path ?: $this->path);
    }
}
