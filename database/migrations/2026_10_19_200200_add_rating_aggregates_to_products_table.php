<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The average rating used to be computed per request from every review.
     * It is now stored on the product and recomputed, inside a transaction
     * holding a lock on the product row, each time a review is written or
     * removed (App\Services\ReviewService). Existing reviews are folded in
     * here.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rating_average', 3, 2)->default(0)->after('stock');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_average');
        });

        DB::table('reviews')
            ->selectRaw('product_id, COUNT(*) as review_count, AVG(rating) as review_average')
            ->groupBy('product_id')
            ->orderBy('product_id')
            ->each(function ($row) {
                DB::table('products')->where('id', $row->product_id)->update([
                    'rating_count' => (int) $row->review_count,
                    'rating_average' => round((float) $row->review_average, 2),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['rating_average', 'rating_count']);
        });
    }
};
