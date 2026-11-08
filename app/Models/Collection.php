<?php

namespace App\Models;

use Database\Factories\CollectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An editorial or seasonal grouping of products, made by an operator. Manual collections list products by hand;
 * dynamic ones hold a rule (category, brand, price range, attributes, in stock) that is resolved by the search at
 * the moment of viewing. Public only inside its start and end dates.
 */
class Collection extends Model
{
    /** @use HasFactory<CollectionFactory> */
    use HasFactory;

    public const MANUAL = 'manual';

    public const DYNAMIC = 'dynamic';

    protected $fillable = ['title', 'slug', 'description', 'type', 'rule', 'starts_at', 'ends_at', 'visibility', 'curator_id', 'position'];

    protected $casts = [
        'rule' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function curator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'curator_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_products')->withPivot('position')->orderByPivot('position');
    }

    /** Public and inside its dates. */
    public function scopeLive(Builder $query): void
    {
        $query->where('visibility', 'public')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isLive(): bool
    {
        return $this->visibility === 'public'
            && ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->ends_at === null || $this->ends_at->gt(now()));
    }
}
