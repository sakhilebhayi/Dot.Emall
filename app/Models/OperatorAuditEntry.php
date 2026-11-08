<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of the operator audit trail: who (name kept even if the account is later deleted), what, about what, when and why.
 * Append-only by construction: the model refuses to be updated or deleted, and the application has no code that tries.
 */
class OperatorAuditEntry extends Model
{
    protected $table = 'operator_audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The audit log is append-only.'));
        static::deleting(fn () => throw new LogicException('The audit log is append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
