<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order history must outlive the catalogue.
     *
     * order_items used to hold only product_id with ON DELETE CASCADE and
     * no copy of what was bought: deleting a product silently deleted every
     * order line that referenced it. Each line now carries its own snapshot
     * (name, store, seller) and product_id becomes nullable with
     * ON DELETE SET NULL. The snapshot columns are nullable because rows
     * written before this migration have none until the next migration
     * (backfill_order_snapshots) fills them in from the live catalogue.
     *
     * orders gains the shipping contact, and the promo rules as they were
     * at checkout, so that cancelling a line can recompute the discount
     * without depending on a promo code row that a seller may since have
     * edited or deleted.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            $table->string('product_name')->nullable()->after('product_id');
            $table->foreignId('store_id')->nullable()->after('product_name')->constrained('stores')->nullOnDelete();
            $table->string('store_name')->nullable()->after('store_id');
            $table->foreignId('seller_id')->nullable()->after('store_name')->constrained('users')->nullOnDelete();
            // This line's share of the order's promo discount; `total`
            // stays the gross line total.
            $table->decimal('discount_amount', 12, 2)->default(0)->after('total');

            $table->index('order_id', 'order_items_order_id_index');
            $table->index('product_id', 'order_items_product_id_index');
            $table->index('status', 'order_items_status_index');
            $table->index(['seller_id', 'status'], 'order_items_seller_id_status_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_name')->nullable()->after('shipping_address');
            $table->string('shipping_phone', 40)->nullable()->after('shipping_name');
            $table->foreignId('promo_code_id')->nullable()->after('promo_code')->constrained('promo_codes')->nullOnDelete();
            $table->foreignId('promo_store_id')->nullable()->after('promo_code_id')->constrained('stores')->nullOnDelete();
            $table->string('promo_discount_type', 16)->nullable()->after('promo_store_id');
            $table->decimal('promo_discount_value', 10, 2)->nullable()->after('promo_discount_type');
            // Set the first time the promo's use is given back; guards
            // against giving it back twice.
            $table->timestamp('promo_released_at')->nullable()->after('promo_discount_value');

            $table->index('user_id', 'orders_user_id_index');
            $table->index('status', 'orders_status_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_user_id_index');
            $table->dropIndex('orders_status_index');
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropConstrainedForeignId('promo_store_id');
            $table->dropColumn([
                'shipping_name', 'shipping_phone', 'promo_discount_type',
                'promo_discount_value', 'promo_released_at',
            ]);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_items_order_id_index');
            $table->dropIndex('order_items_product_id_index');
            $table->dropIndex('order_items_status_index');
            $table->dropIndex('order_items_seller_id_status_index');
            $table->dropConstrainedForeignId('store_id');
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['product_name', 'store_name', 'discount_amount']);
            $table->dropForeign(['product_id']);
        });

        // Lines whose product has since been deleted have no product_id and
        // cannot go back to NOT NULL; the column is left nullable in that
        // case rather than destroying order history to satisfy a rollback.
        if (! DB::table('order_items')->whereNull('product_id')->exists()) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->unsignedBigInteger('product_id')->nullable(false)->change();
            });
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
