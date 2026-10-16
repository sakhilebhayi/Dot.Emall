<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store-scoped, matching how sellers already own everything else in
     * this marketplace (Store -> Product) -- a promo code is one seller's
     * own discount on their own shop's items, not a marketplace-wide
     * coupon, the same way a real Etsy-style shop coupon only discounts
     * that shop's items in a mixed-seller cart.
     */
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('discount_type'); // 'percent' | 'fixed'
            $table->decimal('discount_value', 10, 2);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
