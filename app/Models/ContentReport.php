<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's report of a review, a question, an answer, a listing or a store, for the operator queue. */
class ContentReport extends Model
{
    public const REASONS = ['spam', 'fake', 'offensive', 'off_topic', 'personal_info', 'other'];

    public const TYPES = ['review', 'question', 'answer', 'product', 'store'];

    protected $fillable = ['reportable_type', 'reportable_id', 'reporter_id', 'reason', 'details'];

    protected $casts = ['handled_at' => 'datetime'];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
