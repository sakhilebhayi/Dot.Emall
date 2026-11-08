<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Review;
use App\Services\Community\StoreRatings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every product's rating (count and average) and every store's rating from the PUBLISHED reviews
 * themselves. Run it after a restore, an import, or any time a stored aggregate is suspected of drifting.
 * With --dry-run it only reports how many differ.
 */
class RebuildReviews extends Command
{
    protected $signature = 'emall:reviews-rebuild {--dry-run : Report differences without writing}';

    protected $description = 'Recompute product and store rating aggregates from the reviews';

    public function handle(StoreRatings $stores): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $checked = 0;

        $truth = Review::query()->where('status', Review::PUBLISHED)->groupBy('product_id')
            ->selectRaw('product_id, COUNT(*) as c, AVG(rating) as a')->get()->keyBy('product_id');

        Product::withTrashed()->orderBy('id')->select(['id', 'rating_count', 'rating_average'])->chunkById(500, function ($products) use ($truth, $dry, &$fixed, &$checked) {
            foreach ($products as $product) {
                $checked++;
                $count = (int) ($truth[$product->id]->c ?? 0);
                $average = $count > 0 ? round((float) $truth[$product->id]->a, 2) : 0.0;

                if ((int) $product->rating_count !== $count || round((float) $product->rating_average, 2) !== $average) {
                    $fixed++;

                    if (! $dry) {
                        DB::table('products')->where('id', $product->id)->update(['rating_count' => $count, 'rating_average' => $average]);
                    }
                }
            }
        });

        $storeCount = $dry ? 0 : $stores->refreshAll();

        $this->info(($dry ? 'Would fix' : 'Fixed')." {$fixed} of {$checked} products".($dry ? '.' : "; refreshed {$storeCount} stores."));

        return self::SUCCESS;
    }
}
