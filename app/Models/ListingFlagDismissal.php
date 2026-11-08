<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An operator looked at a listing the rules flagged and judged it fine, for one rule, with a note. */
class ListingFlagDismissal extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
