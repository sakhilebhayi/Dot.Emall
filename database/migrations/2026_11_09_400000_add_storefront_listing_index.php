<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The storefront's default listing (products page, "latest" on the home page, new arrivals) is
 * `where status = 'approved' and is_active = true ... order by created_at desc limit 12`. With only (status, is_active) indexed,
 * PostgreSQL read every listed product and sorted them to hand back twelve (5,750 rows touched on the demo data times 50, 12 with
 * this index, found by EXPLAIN in the R-43 integration pass).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'is_active', 'created_at'], 'products_listing_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_listing_index');
        });
    }
};
