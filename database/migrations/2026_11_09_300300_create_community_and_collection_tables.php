<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product Q&A, content reports, the review extras and editorial collections.
     *
     * The existing `reviews` table keeps its unique (product_id, user_id): one review per buyer per product, which is
     * stricter than "one per order item". verified_purchase is written by ReviewService from a DELIVERED order line,
     * never taken from a request. Existing reviews all stay published; the verified flag is back-filled from the order
     * lines, so history is not rewritten.
     */
    public function up(): void
    {
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 16)->default('published'); // published | held | hidden
            $table->string('held_reason', 64)->nullable();
            $table->unsignedInteger('answers_count')->default(0);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'created_at'], 'product_questions_list_index');
            $table->index(['user_id', 'product_id', 'created_at'], 'product_questions_throttle_index');
        });

        Schema::create('product_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('product_questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->text('body');
            $table->string('status', 16)->default('published'); // published | hidden
            $table->boolean('is_seller')->default(false);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['question_id', 'status'], 'product_answers_question_index');
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reportable_type', 32); // review | question | answer
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open'); // open | actioned | dismissed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('resolution', 255)->nullable();
            $table->timestamps();

            $table->unique(['reportable_type', 'reportable_id', 'reporter_id'], 'content_reports_unique');
            $table->index(['status', 'created_at'], 'content_reports_queue_index');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->boolean('verified_purchase')->default(false);
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->string('status', 16)->default('published'); // published | held | removed
            $table->string('held_reason', 64)->nullable();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->string('body_hash', 40)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->unique('order_item_id', 'reviews_order_item_unique');
            $table->index(['product_id', 'status'], 'reviews_product_status_index');
            $table->index('body_hash', 'reviews_body_hash_index');
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['review_id', 'sort_order'], 'review_photos_review_index');
        });

        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['review_id', 'user_id'], 'review_votes_unique');
        });

        Schema::create('review_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->text('body');
            $table->string('status', 16)->default('published'); // published | hidden
            $table->timestamps();
        });

        Schema::create('store_rating_aggregates', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->primary();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('type', 12)->default('manual'); // manual | dynamic
            $table->json('rule')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('visibility', 12)->default('draft'); // draft | public
            $table->foreignId('curator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['visibility', 'starts_at', 'ends_at'], 'collections_window_index');
        });

        Schema::create('collection_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['collection_id', 'product_id'], 'collection_products_unique');
        });

        $this->backfillVerifiedPurchases();
    }

    private function backfillVerifiedPurchases(): void
    {
        DB::table('reviews')->orderBy('id')->chunkById(500, function ($reviews) {
            foreach ($reviews as $review) {
                $item = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->where('orders.user_id', $review->user_id)
                    ->where('order_items.product_id', $review->product_id)
                    ->where('order_items.status', OrderStatus::Delivered->value)
                    ->orderBy('order_items.id')
                    ->value('order_items.id');

                if ($item !== null) {
                    DB::table('reviews')->where('id', $review->id)->update([
                        'verified_purchase' => true,
                        'order_item_id' => DB::table('reviews')->where('order_item_id', $item)->exists() ? null : $item,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_products');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('store_rating_aggregates');
        Schema::dropIfExists('review_responses');
        Schema::dropIfExists('review_votes');
        Schema::dropIfExists('review_photos');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_order_item_unique');
            $table->dropIndex('reviews_product_status_index');
            $table->dropIndex('reviews_body_hash_index');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['verified_purchase', 'order_item_id', 'status', 'held_reason', 'helpful_count', 'body_hash', 'edited_at', 'moderated_at']);
        });

        Schema::dropIfExists('content_reports');
        Schema::dropIfExists('product_answers');
        Schema::dropIfExists('product_questions');
    }
};
