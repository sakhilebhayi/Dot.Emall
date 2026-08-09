<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Independent of the existing is_active column -- is_active
            // keeps its current meaning (buyer-facing visibility toggle,
            // untouched by this migration); status is a second gate.
            $table->string('status')->default('pending_approval')->after('is_active');
            $table->text('rejected_reason')->nullable()->after('status');
            $table->foreignId('reviewed_by')->nullable()->after('rejected_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'rejected_reason', 'reviewed_at']);
        });
    }
};
