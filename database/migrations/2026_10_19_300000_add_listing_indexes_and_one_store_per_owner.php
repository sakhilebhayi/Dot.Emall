<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the queries the storefront and seller screens actually run
     * (name search, the browsable filter, a store's listings by status) and
     * the one-store-per-owner rule, made a database fact instead of an
     * application habit.
     *
     * Every index is created only if absent, so re-running is harmless, and
     * only plain indexes are used so the same migration is valid on SQLite,
     * PostgreSQL and MySQL.
     *
     * The unique index on stores.owner_id is skipped, with a warning in the
     * log, if any owner already has more than one store: collapsing stores
     * would destroy listings and order history, and which store to keep is
     * the owner's decision, not a migration's. User::store() resolves such an
     * owner to their oldest store deterministically in the meantime.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasIndex('products', 'products_name_index')) {
                $table->index('name', 'products_name_index');
            }
            if (! Schema::hasIndex('products', 'products_status_is_active_index')) {
                $table->index(['status', 'is_active'], 'products_status_is_active_index');
            }
            if (! Schema::hasIndex('products', 'products_store_id_status_index')) {
                $table->index(['store_id', 'status'], 'products_store_id_status_index');
            }
        });

        if (! Schema::hasIndex('stores', 'stores_owner_id_unique')) {
            $duplicates = DB::table('stores')->select('owner_id')->groupBy('owner_id')->havingRaw('COUNT(*) > 1')->pluck('owner_id');

            if ($duplicates->isEmpty()) {
                Schema::table('stores', function (Blueprint $table) {
                    $table->unique('owner_id', 'stores_owner_id_unique');
                });
            } else {
                Log::warning('stores.owner_id unique index NOT added: these owners already have more than one store and need a decision.', ['owner_ids' => $duplicates->all()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['products_name_index', 'products_status_is_active_index', 'products_store_id_status_index'] as $index) {
                if (Schema::hasIndex('products', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasIndex('stores', 'stores_owner_id_unique')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->dropUnique('stores_owner_id_unique');
            });
        }
    }
};
