<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single checkout can span multiple sellers' stores in one Order
     * (CartController::checkout() creates one Order per cart, not one per
     * store) -- orders.status alone can't represent "seller A shipped
     * their item, seller B hasn't" for a mixed-seller order. Per-item
     * status is the correct granularity for fulfillment: each seller only
     * ever controls their own OrderItem rows. orders.status is then
     * derived from the items (see SellerOrderController) rather than set
     * directly by a seller action, keeping the existing dashboard's
     * sum(total) where status='delivered' revenue metric meaningful.
     *
     * Free-form string, no enum, matching orders.status's existing
     * unconstrained-string convention (see wiki.md's own Open Questions).
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'status')) {
                $table->string('status')->default('pending')->after('total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
