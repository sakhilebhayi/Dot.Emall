<?php

namespace App\Models;

use Database\Factories\ProductQuestionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductQuestion extends Model
{
    /** @use HasFactory<ProductQuestionFactory> */
    use HasFactory;

    public const PUBLISHED = 'published';

    public const HELD = 'held';

    public const HIDDEN = 'hidden';

    protected $fillable = ['product_id', 'user_id', 'body'];

    protected $casts = ['moderated_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ProductAnswer::class, 'question_id')->orderBy('id');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::PUBLISHED);
    }
}
