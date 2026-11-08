<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Variants, options, the stock-movement audit trail and price history.
 *
 * Every product gets at least one variant. A product with no options has
 * exactly one, the implicit default variant, which mirrors products.price /
 * stock / sku (App\Services\Catalogue\VariantSync keeps the two in step), so
 * nothing that reads or writes products.price or products.stock changes.
 * Cart lines and order lines point at the variant.
 *
 * product_price_history starts the day this migration runs: nothing is
 * invented for the past (D-10), so "was" prices appear 30 days from now at
 * the earliest, for products whose price actually changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('values'); // ordered list of strings
            $table->timestamps();

            $table->unique(['product_id', 'name'], 'product_options_product_name_unique');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100)->nullable()->unique();
            $table->json('options')->nullable();                 // {"Size": "M", "Colour": "Red"}
            $table->string('option_key', 255)->default('');      // normalised options, unique per product
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('stock')->default(0);
            $table->unsignedInteger('stock_version')->default(0); // bumped on every stock change
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('image_id')->nullable()->constrained('product_images')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'option_key'], 'product_variants_product_options_unique');
            $table->index(['product_id', 'is_active'], 'product_variants_product_active_index');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->unsignedInteger('stock_before');
            $table->unsignedInteger('stock_after');
            $table->string('reason', 32); // initial | sale | cancellation | expiry | return | seller_edit | adjustment
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'id'], 'stock_movements_variant_index');
            $table->index(['product_id', 'created_at'], 'stock_movements_product_time_index');
            $table->index('order_id', 'stock_movements_order_index');
        });

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['variant_id', 'changed_at'], 'price_history_variant_time_index');
            $table->index('product_id', 'price_history_product_index');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->cascadeOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('variant_label', 190)->nullable()->after('product_name');
            $table->string('sku', 100)->nullable()->after('variant_label');
        });

        $this->backfill();

        // One cart line per buyer and VARIANT (it was per product, which is the same thing for a one-variant product).
        Schema::table('cart_items', function (Blueprint $table) {
            $table->index('user_id', 'cart_items_user_id_index');
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
        });
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['user_id', 'variant_id'], 'cart_items_user_variant_unique');
        });
    }

    private function backfill(): void
    {
        $now = now();

        DB::table('products')->orderBy('id')->chunkById(500, function ($products) use ($now) {
            foreach ($products as $product) {
                $variantId = DB::table('product_variants')->insertGetId([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'options' => null,
                    'option_key' => '',
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'is_active' => true,
                    'is_default' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_price_history')->insert([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'old_price' => null,
                    'new_price' => $product->price,
                    'changed_at' => $now,
                ]);

                DB::table('cart_items')->where('product_id', $product->id)->update(['variant_id' => $variantId]);
                DB::table('order_items')->where('product_id', $product->id)->update(['variant_id' => $variantId, 'sku' => $product->sku]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('cart_items_user_variant_unique');
        });

        // Keep the first line per (user, product) if variants had split a product across several lines.
        DB::table('cart_items')->orderBy('id')->get()->groupBy(fn ($row) => $row->user_id.'-'.$row->product_id)
            ->each(fn ($rows) => $rows->skip(1)->each(fn ($row) => DB::table('cart_items')->where('id', $row->id)->delete()));

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
            $table->dropIndex('cart_items_user_id_index');
            $table->dropConstrainedForeignId('variant_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn(['variant_label', 'sku']);
        });

        Schema::dropIfExists('product_price_history');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_options');
    }
};
