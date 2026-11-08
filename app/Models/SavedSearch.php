<?php

namespace App\Models;

use App\Services\Search\SearchRequest;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory;

    public const FREQUENCIES = ['off', 'daily', 'weekly'];

    protected $fillable = ['user_id', 'name', 'query', 'filters', 'notify_frequency'];

    protected $casts = [
        'filters' => 'array',
        'seen_product_ids' => 'array',
        'last_notified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The search this saved search runs. */
    public function toRequest(): SearchRequest
    {
        return SearchRequest::fromArray(array_merge($this->filters ?? [], ['q' => (string) $this->query, 'sort' => 'newest']));
    }
}
