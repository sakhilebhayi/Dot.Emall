<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit trail of what Paystack told us and what we did with it:
     * applied, duplicate, unmatched (unknown reference, wrong amount or
     * currency) or needs_review. Operators read it; nothing depends on it.
     */
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16);
            $table->string('event', 64);
            $table->string('reference', 100)->nullable();
            $table->string('outcome', 24);
            $table->string('reason', 120)->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['outcome', 'created_at'], 'payment_events_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
