<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the R-43 performance pass found missing by running EXPLAIN ANALYZE on PostgreSQL with 60,000 listings and 32,000 orders
 * (docs/R43_QA_secperf.md has the before and after numbers):
 *
 *  - order_groups (store_id, status): the seller desk asks for "this store's parcels" by store_id, but the only index led with
 *    seller_id, so every desk page read every parcel on the marketplace.
 *  - order_items (order_group_id): the existence checks behind the desk's queue counts, the parcel page and the fulfilment board join
 *    items to their parcel by this column, which had no index at all.
 *  - orders (created_at): "counted orders in the last N days" (trending, featured stores, popular categories on the home page,
 *    seller analytics) is a date-range read.
 *  - search_documents (indexed_at): the operator health page reads the newest index time (max) and compares it to listing edits.
 *
 * Each index is created only if it is not there and dropped only if it is, so the migration is safe to re-run on any database
 * (SQLite and PostgreSQL alike).
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> index name => [table, columns] */
    private const INDEXES = [
        'order_groups_store_status_index' => ['order_groups', ['store_id', 'status']],
        'order_items_order_group_index' => ['order_items', ['order_group_id']],
        'orders_created_at_index' => ['orders', ['created_at']],
        'search_documents_indexed_at_index' => ['search_documents', ['indexed_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
