<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one emall:reconcile run found. */
class ReconcileRun extends Model
{
    protected $guarded = [];

    protected $casts = ['ran_at' => 'datetime', 'mismatches' => 'array'];
}
