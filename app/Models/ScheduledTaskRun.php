<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The outcome of one run of a scheduled command, written by the scheduler listener (Operator\TaskRunRecorder). */
class ScheduledTaskRun extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ran_at' => 'datetime'];
}
