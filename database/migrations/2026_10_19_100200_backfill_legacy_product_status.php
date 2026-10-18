<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keeps pre-moderation listings visible.
     *
     * 2026_08_09_000002 added products.status with DEFAULT 'pending_approval'
     * and no backfill, so every listing that already existed was silently
     * hidden from the storefront the moment that migration ran. Nothing but
     * the moderation gate itself ever wrote a status, so a row is a
     * "legacy listing" exactly when it was created before the gate shipped
     * (commit 0cdd4c1, 2026-08-09 09:10:33 +02:00 = 07:10:33 UTC) AND has
     * never been touched by the review flow (no reviewer, review time or
     * rejection reason). Before the gate there was no seller UI at all, so
     * no genuine seller submission can predate that instant.
     *
     * Idempotent: rows already approved or rejected, and every listing
     * submitted since the gate, are untouched; a second run changes
     * nothing. Fresh databases have no rows and this is a no-op.
     */
    private const GATE_SHIPPED_AT_UTC = '2026-08-09 07:10:33';

    public function up(): void
    {
        DB::table('products')
            ->where('status', 'pending_approval')
            ->whereNull('reviewed_at')
            ->whereNull('reviewed_by')
            ->whereNull('rejected_reason')
            ->where(fn ($q) => $q->whereNull('created_at')->orWhere('created_at', '<', self::GATE_SHIPPED_AT_UTC))
            ->update(['status' => 'approved']);
    }

    public function down(): void
    {
        // Not reversible: after this runs there is no record of which rows
        // were legacy, and re-hiding live listings would be the bug again.
    }
};
