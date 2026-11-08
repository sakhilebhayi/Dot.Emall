<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A spec a category's products can carry (Laptops: ram, storage, processor, screen size). */
class CategoryAttribute extends Model
{
    public const TYPES = ['text', 'number', 'enum', 'boolean'];

    protected $fillable = ['category_id', 'key', 'label', 'type', 'unit', 'options', 'is_facetable', 'is_required', 'position'];

    protected $casts = ['options' => 'array', 'is_facetable' => 'boolean', 'is_required' => 'boolean'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'category_id');
    }
}
