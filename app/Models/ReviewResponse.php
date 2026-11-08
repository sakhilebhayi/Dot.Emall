<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The seller's one public reply to a review. The seller cannot edit it into something else or delete it. */
class ReviewResponse extends Model
{
    protected $fillable = ['review_id', 'user_id', 'store_id', 'body'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
