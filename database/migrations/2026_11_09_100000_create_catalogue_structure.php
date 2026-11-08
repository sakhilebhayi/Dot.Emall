<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-43 categories as a real tree, brands, and structured product attributes.
 *
 * store_categories already had an unused parent_id; it gets ordering, a
 * materialised `path` ("/1/5/") so a whole subtree is one indexed LIKE, a
 * depth, an image and an on/off switch. Attribute definitions hang off a
 * category (children inherit their parents' definitions in the service);
 * product_attributes hold the values, with a numeric twin for range facets.
 *
 * Strings, never database enums, so a new kind is not a table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('parent_id');
            $table->unsignedTinyInteger('depth')->default(0)->after('sort_order');
            $table->string('path', 255)->nullable()->after('depth');
            $table->text('description')->nullable()->after('path');
            $table->string('image_path')->nullable()->after('description');
            $table->boolean('is_active')->default(true)->after('image_path');

            $table->index(['parent_id', 'sort_order'], 'store_categories_parent_sort_index');
            if (DB::getDriverName() !== 'pgsql') {
                $table->index('path', 'store_categories_path_index');
            }
        });

        // A prefix match (path LIKE '/1/5/%') can only use a PostgreSQL index built with the pattern operator class
        // (the default one is for equality and ordering in the database's collation). Elsewhere a plain index serves.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX store_categories_path_index ON store_categories (path varchar_pattern_ops)');
        }

        // Categories that existed before the tree: give each its path and depth from the parent column.
        $rows = DB::table('store_categories')->get()->keyBy('id');
        foreach ($rows as $row) {
            $ids = [];
            $node = $row;
            $guard = 0;
            while ($node !== null && $guard++ < 20) {
                array_unshift($ids, $node->id);
                $node = $node->parent_id ? ($rows[$node->parent_id] ?? null) : null;
            }
            DB::table('store_categories')->where('id', $row->id)->update(['path' => '/'.implode('/', $ids).'/', 'depth' => count($ids) - 1]);
        }

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('category_id')->constrained('brands')->nullOnDelete();
            // Seller-set: at or below this many units the seller is told stock is low.
            $table->unsignedInteger('low_stock_threshold')->default(5)->after('stock');

            $table->index(['category_id', 'status', 'is_active'], 'products_category_status_active_index');
        });

        Schema::create('category_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('store_categories')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 80);
            $table->string('type', 16)->default('text'); // text | number | enum | boolean
            $table->string('unit', 16)->nullable();
            $table->json('options')->nullable();          // allowed values for an enum
            $table->boolean('is_facetable')->default(false);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'key'], 'category_attributes_category_key_unique');
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label', 80);
            $table->string('value', 255);
            $table->decimal('value_number', 16, 4)->nullable();
            $table->string('unit', 16)->nullable();
            $table->boolean('is_facetable')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'key'], 'product_attributes_product_key_unique');
            // The facet queries: counts per value of a key, and ranges of a numeric key.
            $table->index(['key', 'value'], 'product_attributes_key_value_index');
            $table->index(['key', 'value_number'], 'product_attributes_key_number_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('category_attributes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_category_status_active_index');
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn('low_stock_threshold');
        });

        Schema::dropIfExists('brands');

        Schema::table('store_categories', function (Blueprint $table) {
            $table->dropIndex('store_categories_parent_sort_index');
            $table->dropIndex('store_categories_path_index');
            $table->dropColumn(['sort_order', 'depth', 'path', 'description', 'image_path', 'is_active']);
        });
    }
};
