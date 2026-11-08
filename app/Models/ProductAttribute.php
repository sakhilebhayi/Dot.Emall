<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    protected $fillable = ['product_id', 'key', 'label', 'value', 'value_number', 'unit', 'is_facetable', 'position'];

    protected $casts = ['value_number' => 'decimal:4', 'is_facetable' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
