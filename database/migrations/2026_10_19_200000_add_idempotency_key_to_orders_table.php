<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-submit token (a UUID rendered into the checkout form) makes
     * placing an order idempotent: the same submission can never create a
     * second order. Until now a double submit was only stopped because the
     * first submit emptied the cart. Unique per buyer, not global, so one
     * buyer's token can never collide with, or reveal, another's. Existing
     * orders have no token (NULL), and NULLs never collide.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('shipping_phone');
            $table->unique(['user_id', 'idempotency_key'], 'orders_user_id_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_user_id_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
