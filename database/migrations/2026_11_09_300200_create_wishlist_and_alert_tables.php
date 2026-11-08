<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wishlists, saved searches and alerts. variant_id is a plain number (the variants table belongs to another
     * workstream and may not exist yet); variant_key is 0 when there is no variant, so the unique indexes treat "no
     * variant" as a value on every database (a NULL would let duplicates in on PostgreSQL and SQLite).
     */
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->default('My list');
            $table->boolean('is_shared')->default(false);
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamps();

            $table->index('user_id', 'wishlists_user_index');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wishlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->string('note', 255)->nullable();
            $table->decimal('price_when_added', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['wishlist_id', 'product_id', 'variant_key'], 'wishlist_items_unique');
            $table->index('product_id', 'wishlist_items_product_index');
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('query', 120)->nullable();
            $table->json('filters')->nullable();
            $table->string('notify_frequency', 12)->default('off'); // off | daily | weekly
            $table->json('seen_product_ids')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'notify_frequency'], 'saved_searches_user_index');
        });

        Schema::create('product_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('variant_key')->default(0);
            $table->string('kind', 16); // back_in_stock | price_drop
            $table->decimal('baseline_price', 12, 2)->nullable();
            $table->decimal('target_price', 12, 2)->nullable();
            $table->decimal('last_notified_price', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id', 'variant_key', 'kind'], 'product_alerts_unique');
            $table->index(['kind', 'active'], 'product_alerts_active_index');
            $table->index('product_id', 'product_alerts_product_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_alerts');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlists');
    }
};
