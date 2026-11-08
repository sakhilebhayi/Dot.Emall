<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-43 seller desk (W6): the seller's private notes on a parcel, and the conditions a promo code can carry (a minimum
 * spend, a cap per buyer and the words shown to buyers). Nothing here is read by buyers except the conditions text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_order_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();

            $table->index(['order_group_id', 'id'], 'seller_order_notes_group_index');
        });

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->decimal('min_spend', 12, 2)->nullable()->after('discount_value');
            $table->unsignedInteger('per_user_limit')->nullable()->after('max_uses');
            $table->string('conditions', 500)->nullable()->after('free_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn(['min_spend', 'per_user_limit', 'conditions']);
        });
        Schema::dropIfExists('seller_order_notes');
    }
};
