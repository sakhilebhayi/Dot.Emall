<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-store shipping methods, the seller group of an order (one per store in
 * an order: its delivery cost, VAT part and delivery estimate), shipments
 * with seller-entered tracking, and guest orders.
 *
 * orders.user_id becomes nullable: a guest order has no account, only a
 * guest_email and a magic-link token (hashed here, recomputable from the app
 * key so a later e-mail can carry the same link; rotating bumps the version).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 16)->default('flat'); // flat | free_over | collection
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('free_over', 12, 2)->nullable();
            $table->unsignedSmallInteger('handling_days')->default(1);
            $table->unsignedSmallInteger('window_min_days')->default(2);
            $table->unsignedSmallInteger('window_max_days')->default(5);
            $table->text('collection_address')->nullable();
            $table->string('instructions', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'shipping_methods_store_active_index');
        });

        Schema::create('shipping_method_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->string('country', 2)->default('ZA');
            $table->string('province', 60)->nullable();
            $table->string('postal_from', 10)->nullable();
            $table->string('postal_to', 10)->nullable();
            $table->decimal('price', 12, 2)->nullable(); // overrides the method price in this region
            $table->timestamps();

            $table->index('shipping_method_id', 'shipping_regions_method_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_total', 12, 2)->default(0)->after('discount_amount');
            $table->decimal('vat_total', 12, 2)->default(0)->after('delivery_total');
            $table->boolean('promo_free_shipping')->default(false)->after('promo_released_at');
            $table->json('shipping_snapshot')->nullable()->after('shipping_phone');
            $table->string('guest_email', 190)->nullable()->after('user_id');
            $table->string('guest_token_hash', 64)->nullable()->after('guest_email');
            $table->unsignedInteger('guest_token_version')->default(1)->after('guest_token_hash');
            $table->timestamp('guest_token_expires_at')->nullable()->after('guest_token_version');
            $table->timestamp('guest_attached_at')->nullable()->after('guest_token_expires_at');
            $table->timestamp('delivered_at')->nullable();

            $table->index('guest_email', 'orders_guest_email_index');
            $table->index('guest_token_hash', 'orders_guest_token_index');
            $table->unique(['guest_email', 'idempotency_key'], 'orders_guest_idempotency_unique');
        });

        Schema::table('promo_codes', function (Blueprint $table) {
            $table->boolean('free_shipping')->default(false)->after('discount_value');
        });

        Schema::create('order_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('store_name')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->string('method_name', 80)->nullable();
            $table->string('method_type', 16)->default('arranged'); // flat | free_over | collection | arranged
            $table->unsignedBigInteger('delivery_cents')->default(0);
            $table->unsignedBigInteger('vat_cents')->default(0);
            $table->boolean('vat_registered')->default(false);
            $table->unsignedSmallInteger('handling_days')->default(0);
            $table->unsignedSmallInteger('window_min_days')->default(0);
            $table->unsignedSmallInteger('window_max_days')->default(0);
            $table->date('estimated_from')->nullable();
            $table->date('estimated_to')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'store_id'], 'order_groups_order_store_unique');
            $table->index(['seller_id', 'status'], 'order_groups_seller_status_index');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('order_group_id')->nullable()->after('order_id')->constrained('order_groups')->nullOnDelete();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_group_id')->constrained('order_groups')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('carrier_key', 40)->nullable();
            $table->string('carrier_name', 80)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->timestamp('shipped_at');
            $table->date('estimated_from')->nullable();
            $table->date('estimated_to')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('order_group_id', 'shipments_group_index');
            $table->index('order_id', 'shipments_order_index');
            $table->index('tracking_number', 'shipments_tracking_index');
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();

            $table->unique('order_item_id', 'shipment_items_item_unique');
            $table->index('shipment_id', 'shipment_items_shipment_index');
        });

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('description', 190);
            $table->string('location', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'occurred_at'], 'shipment_events_shipment_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');

        Schema::table('order_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('order_group_id'));
        Schema::dropIfExists('order_groups');

        Schema::table('promo_codes', fn (Blueprint $table) => $table->dropColumn('free_shipping'));

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('orders_guest_idempotency_unique');
            $table->dropIndex('orders_guest_email_index');
            $table->dropIndex('orders_guest_token_index');
            $table->dropColumn([
                'delivery_total', 'vat_total', 'promo_free_shipping', 'shipping_snapshot', 'guest_email',
                'guest_token_hash', 'guest_token_version', 'guest_token_expires_at', 'guest_attached_at', 'delivered_at',
            ]);
        });

        Schema::dropIfExists('shipping_method_regions');
        Schema::dropIfExists('shipping_methods');
    }
};
