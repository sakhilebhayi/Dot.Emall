<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCommissionOverride extends Model
{
    protected $fillable = ['store_id', 'percent', 'note', 'set_by'];

    protected $casts = ['percent' => 'decimal:2'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
