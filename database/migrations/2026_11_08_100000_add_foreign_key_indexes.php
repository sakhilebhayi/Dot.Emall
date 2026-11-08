<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL (production) does not index a foreign key by itself, and neither does SQLite. These are the ones a
     * page or a cascade actually walks: every listing page loads its photos by product_id, the category filter reads
     * products.category_id, refunds are summed per payment, and deleting a product, a user or a payment scans the
     * child tables below. Without them each of those is a full table scan that grows with the marketplace
     * (measured on 5k products / 200k order lines / 80k orders: harmless today, linear tomorrow).
     *
     * Every index is created only if absent, so a re-run is harmless. Plain indexes only, valid on every database.
     */
    private const INDEXES = [
        'product_images' => [['product_id', 'sort_order']],
        'products' => [['category_id']],
        'order_items' => [['store_id']],
        'orders' => [['promo_code_id']],
        'reviews' => [['user_id']],
        'cart_items' => [['product_id']],
        'refunds' => [['payment_id']],
        'payment_events' => [['order_id'], ['payment_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_index';

                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $sets) {
            foreach ($sets as $columns) {
                $name = $table.'_'.implode('_', $columns).'_index';

                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
