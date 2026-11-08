<?php

namespace App\Models;

use App\Enums\ShippingMethodType;
use Database\Factories\ShippingMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingMethod extends Model
{
    /** @use HasFactory<ShippingMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'store_id', 'name', 'type', 'price', 'free_over', 'handling_days', 'window_min_days', 'window_max_days',
        'collection_address', 'instructions', 'is_active', 'position',
    ];

    protected $casts = [
        'type' => ShippingMethodType::class,
        'price' => 'decimal:2',
        'free_over' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function regions(): HasMany
    {
        return $this->hasMany(ShippingMethodRegion::class);
    }
}
