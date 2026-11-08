<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order state machine's audit trail and per-state timestamps, return
 * requests, and the seller ledger.
 *
 * ledger_entries are append-only in their amounts: a sale writes a positive
 * entry, a refund or cancellation writes a negative `reversal` entry that
 * points at the sale. Only `status` (held -> released) and its timestamps
 * ever change. idempotency_key makes every write replay-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 16)->default('system'); // buyer | guest | seller | operator | system
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id'], 'order_status_histories_order_index');
            $table->index('order_item_id', 'order_status_histories_item_index');
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_email', 190)->nullable();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_category', 32); // faulty | not_as_described | wrong_item | changed_mind | other
            $table->text('note')->nullable();
            $table->json('photo_paths')->nullable();
            $table->string('status', 24)->default('requested'); // requested|approved|declined|received|refunded|escalated|closed
            $table->unsignedBigInteger('refund_cents')->default(0);
            $table->timestamp('delivered_at')->nullable();      // the line's delivery, snapshotted for the 7-day rule
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('seller_response')->nullable();         // mandatory when declined
            $table->timestamp('received_at')->nullable();
            $table->boolean('restock')->default(false);
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_reason', 32)->nullable(); // seller_no_response | buyer_disputed_decline
            $table->text('escalation_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'respond_by'], 'return_requests_status_due_index');
            $table->index(['seller_id', 'status'], 'return_requests_seller_status_index');
            $table->index('order_item_id', 'return_requests_item_index');
            $table->index('requester_id', 'return_requests_requester_index');
        });

        Schema::table('refunds', function (Blueprint $table) {
            // cancellation = owed because lines were cancelled (computed by RefundService::reconcile),
            // return = the net value of a returned line, manual = operator-initiated.
            $table->string('kind', 16)->default('cancellation')->after('status');
        });

        Schema::create('store_commission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('percent', 5, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->string('kind', 12)->default('sale'); // sale | reversal
            $table->string('status', 12)->default('held'); // held | released | reversed
            $table->string('currency', 3);
            $table->bigInteger('gross_cents');
            $table->bigInteger('commission_cents');
            $table->bigInteger('net_cents');
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->string('reason', 120)->nullable();
            $table->timestamp('release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamps();

            $table->index(['seller_id', 'status'], 'ledger_entries_seller_status_index');
            $table->index(['status', 'release_at'], 'ledger_entries_release_index');
            $table->index('order_id', 'ledger_entries_order_index');
            $table->index('order_group_id', 'ledger_entries_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('store_commission_overrides');
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('order_status_histories');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['processing_at', 'shipped_at', 'delivered_at', 'ready_at', 'collected_at', 'cancelled_at', 'refunded_at']);
        });
    }
};
