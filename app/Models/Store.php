<?php

namespace App\Models;

use App\Enums\StoreStatus;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_id', 'name', 'slug', 'description', 'logo_path', 'is_active',
        'status', 'status_reason', 'submitted_at', 'reviewed_at', 'reviewed_by',
        'legal_name', 'contact_email', 'contact_phone', 'region', 'city', 'return_policy', 'shipping_policy',
        'vat_registered', 'vat_number', 'registration_number', 'banner_path', 'team_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'status' => StoreStatus::class,
        'vat_registered' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The InfoDot-linked company this store trades under, if the owner linked one. */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function shippingMethods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('position')->orderBy('id');
    }

    public function commissionOverride(): HasOne
    {
        return $this->hasOne(StoreCommissionOverride::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
