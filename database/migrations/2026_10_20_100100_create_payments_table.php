<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Paystack attempt. Financial records outlive the order
     * they were for: order_id is nulled (not cascaded) if an account and its
     * orders are deleted, so a charge is never silently erased.
     *
     * reference is ours and globally unique. (provider, provider_transaction_id)
     * is unique once Paystack's transaction id is known, which is what
     * guarantees one charge can only ever be applied to one payment however
     * the callback and webhook race. NULLs do not collide, so any number of
     * attempts can wait for their id.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 24)->default('paystack');
            $table->string('reference', 100)->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('status', 24)->default('pending');
            $table->string('provider_transaction_id', 64)->nullable();
            $table->text('authorization_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            // A whitelisted summary of Paystack's answer (status, channel,
            // gateway message). Never a key, never card or customer data.
            $table->json('raw_summary')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id'], 'payments_provider_transaction_unique');
            $table->index(['order_id', 'status'], 'payments_order_status_index');
            $table->index('status', 'payments_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
