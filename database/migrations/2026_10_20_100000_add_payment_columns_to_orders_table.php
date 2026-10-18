<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment state for orders. Additive and safe on a live table: every
     * existing order gets requires_payment = false and payment_status =
     * 'unpaid' from the column defaults, which is exactly what they were
     * (placed with no payment step) and keeps them fulfillable and exempt
     * from the unpaid-order expiry. No data is rewritten.
     *
     * payment_status is a string (not a database enum) so adding a state
     * later is not a table rewrite; the App\Enums\PaymentStatus cast refuses
     * anything else.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('requires_payment')->default(false)->after('status');
            $table->string('payment_status', 24)->default('unpaid')->after('requires_payment');
            $table->timestamp('paid_at')->nullable()->after('payment_status');

            // The expiry sweep: unpaid, payment-required orders by age.
            $table->index(['requires_payment', 'payment_status', 'created_at'], 'orders_payment_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_payment_sweep_index');
            $table->dropColumn(['requires_payment', 'payment_status', 'paid_at']);
        });
    }
};
