<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What people do, kept as little as possible and never as a raw user id: actor_hash is a keyed hash of the
     * signed-in user or of an anonymous session token (App\Support\Actor). Product views are one row per person per
     * product (trimmed to 20 per person), relations are the precomputed co-purchase / co-view / editorial links, and
     * commerce_events is the append-only funnel log behind CommerceMetrics.
     */
    public function up(): void
    {
        Schema::create('product_views', function (Blueprint $table) {
            $table->id();
            $table->string('actor_hash', 32);
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('view_count')->default(1);
            $table->timestamp('viewed_at');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['actor_hash', 'product_id'], 'product_views_actor_product_unique');
            $table->index(['actor_hash', 'viewed_at'], 'product_views_actor_time_index');
            $table->index(['product_id', 'viewed_at'], 'product_views_product_time_index');
        });

        Schema::create('product_relations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('related_product_id');
            $table->string('kind', 24); // bought_together | viewed_together
            $table->unsignedInteger('support');
            $table->timestamp('computed_at');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('related_product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['product_id', 'related_product_id', 'kind'], 'product_relations_unique');
            $table->index(['product_id', 'kind', 'support'], 'product_relations_lookup_index');
        });

        Schema::create('complementary_rules', function (Blueprint $table) {
            $table->id();
            $table->string('category_slug', 120);
            $table->string('complement_slug', 120);
            $table->string('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['category_slug', 'complement_slug'], 'complementary_rules_unique');
        });

        Schema::create('commerce_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);
            $table->string('actor_hash', 32);
            $table->date('day');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->bigInteger('value_cents')->nullable();
            $table->string('source', 24)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['name', 'created_at'], 'commerce_events_name_time_index');
            $table->index(['name', 'day', 'actor_hash'], 'commerce_events_name_day_actor_index');
            $table->index(['product_id', 'name', 'created_at'], 'commerce_events_product_index');
            $table->unique(['name', 'order_id'], 'commerce_events_name_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_events');
        Schema::dropIfExists('complementary_rules');
        Schema::dropIfExists('product_relations');
        Schema::dropIfExists('product_views');
    }
};
