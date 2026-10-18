<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 24)->default('refund_pending');
            $table->string('provider_refund_id', 64)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique('provider_refund_id', 'refunds_provider_refund_unique');
            $table->index(['order_id', 'status'], 'refunds_order_status_index');
            $table->index('status', 'refunds_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
