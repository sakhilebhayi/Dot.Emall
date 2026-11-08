<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search storage (R-43 data agent B).
     *
     * search_documents holds one denormalised, already normalised (lowercase, accent-free, plural-folded) text row
     * per listing. The text is normalised in PHP, so nothing here depends on the `unaccent` extension. Visibility,
     * price, stock and rating are NOT copied: queries join `products` so they are always live and no observer has to
     * chase a stock decrement.
     *
     * On PostgreSQL a generated tsvector column (weights A title, B brand and category, C store and attributes,
     * D description) and its GIN index are added. That needs no extension: full-text search is built in. pg_trgm
     * (typo tolerance) is OPTIONAL: it is attempted inside a savepoint and its absence changes nothing but speed.
     */
    public function up(): void
    {
        Schema::create('search_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->string('title');
            $table->text('title_n');
            $table->string('brand')->nullable();
            $table->string('brand_n')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->text('category_n')->nullable();
            $table->text('store_n')->nullable();
            $table->text('attributes_n')->nullable();
            $table->text('description_n')->nullable();
            $table->text('body_n');
            $table->string('region', 64)->nullable();
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamp('indexed_at')->nullable();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index('category_id', 'search_documents_category_index');
            $table->index('brand_n', 'search_documents_brand_index');
            $table->index('region', 'search_documents_region_index');
            $table->index('sold_count', 'search_documents_sold_index');
        });

        Schema::create('search_document_attributes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('attr_key', 64);
            $table->string('attr_label', 128);
            $table->string('value_text', 191);
            $table->string('value_n', 191);
            $table->decimal('value_num', 14, 4)->nullable();
            $table->string('unit', 16)->nullable();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['product_id', 'attr_key'], 'search_doc_attrs_product_index');
            $table->index(['attr_key', 'value_n'], 'search_doc_attrs_key_value_index');
        });

        Schema::create('search_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 80);
            $table->string('kind', 12)->default('word');
            $table->unsignedInteger('doc_freq')->default(1);
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('display')->nullable();

            $table->unique(['kind', 'term'], 'search_terms_kind_term_unique');
            $table->index(['kind', 'doc_freq'], 'search_terms_kind_freq_index');
        });

        Schema::create('search_synonyms', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 64);
            $table->string('term', 80);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['group_key', 'term'], 'search_synonyms_group_term_unique');
            $table->index('term', 'search_synonyms_term_index');
        });

        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query', 120);
            $table->unsignedInteger('result_count')->default(0);
            // A keyed hash of the signed-in user or the anonymous session token. Never the raw id.
            $table->string('actor_hash', 32);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at', 'search_queries_created_index');
            $table->index(['actor_hash', 'created_at'], 'search_queries_actor_index');
            $table->index(['query', 'created_at'], 'search_queries_query_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE search_documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('simple'::regconfig, coalesce(title_n, '')), 'A') ||
                    setweight(to_tsvector('simple'::regconfig, coalesce(brand_n, '')), 'B') ||
                    setweight(to_tsvector('simple'::regconfig, coalesce(category_n, '')), 'B') ||
                    setweight(to_tsvector('simple'::regconfig, coalesce(store_n, '')), 'C') ||
                    setweight(to_tsvector('simple'::regconfig, coalesce(attributes_n, '')), 'C') ||
                    setweight(to_tsvector('simple'::regconfig, coalesce(description_n, '')), 'D')
                ) STORED
            SQL);
            DB::statement('CREATE INDEX search_documents_vector_gin ON search_documents USING gin (search_vector)');

            // Optional. A failed CREATE EXTENSION would abort the whole migration transaction, so it runs in a savepoint.
            try {
                DB::transaction(function () {
                    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                    DB::statement('CREATE INDEX IF NOT EXISTS search_terms_trgm ON search_terms USING gin (term gin_trgm_ops)');
                    DB::statement('CREATE INDEX IF NOT EXISTS search_documents_title_trgm ON search_documents USING gin (title_n gin_trgm_ops)');
                });
            } catch (Throwable $e) {
                Log::warning('pg_trgm is not available on this database; search falls back to edit-distance typo tolerance.', ['error' => class_basename($e)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
        Schema::dropIfExists('search_synonyms');
        Schema::dropIfExists('search_terms');
        Schema::dropIfExists('search_document_attributes');
        Schema::dropIfExists('search_documents');
    }
};
