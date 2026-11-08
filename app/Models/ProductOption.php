<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An axis of choice on a product ("Size": S, M, L), values in the order the seller gave them. */
class ProductOption extends Model
{
    protected $fillable = ['product_id', 'name', 'position', 'values'];

    protected $casts = ['values' => 'array'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
