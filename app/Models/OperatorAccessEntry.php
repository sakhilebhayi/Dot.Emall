<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** POPIA: a record that an operator opened a person's data in the support view. Append-only like the audit trail. */
class OperatorAccessEntry extends Model
{
    protected $table = 'operator_access_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The access log is append-only.'));
        static::deleting(fn () => throw new LogicException('The access log is append-only.'));
    }
}
