<?php

namespace App\Models;

use App\Services\Community\ReviewPhotos;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    public const PUBLISHED = 'published';

    public const HELD = 'held';

    public const REMOVED = 'removed';

    protected $fillable = ['product_id', 'user_id', 'rating', 'body'];

    protected $casts = [
        'verified_purchase' => 'boolean',
        'edited_at' => 'datetime',
        'moderated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Photos live on a private disk; the rows go with the review by cascade, the files must go here.
        static::deleting(fn (Review $review) => app(ReviewPhotos::class)->purge($review));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(ReviewResponse::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ReviewVote::class);
    }

    /** Reviews the public sees: not held for the operator, not removed. */
    public function scopePublished(Builder $query): void
    {
        $query->where('reviews.status', self::PUBLISHED);
    }
}
