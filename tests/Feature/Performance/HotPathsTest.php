<?php

namespace Tests\Feature\Performance;

use App\Models\Collection;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\StoreCategory;
use App\Services\Community\StoreRatings;
use App\Services\Discovery\Collections;
use App\Services\Operator\ListingFlags;
use App\Services\ProductImages;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * R-43 performance pass (docs/R43_QA_secperf.md): the changes that took work out of the hot paths, each pinned so it cannot quietly come back.
 */
class HotPathsTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    /** The price rule as it was first written: for every listing, collect its category's OTHER approved prices and take their median. */
    private function naivePriceFlags(): array
    {
        $out = [];
        $c = config('emall.flags');

        foreach (DB::table('products')->whereIn('status', ['pending_approval', 'approved'])->whereNotNull('category_id')->whereNull('deleted_at')->get() as $p) {
            $peers = DB::table('products')->where('category_id', $p->category_id)->where('status', 'approved')->whereNull('deleted_at')->where('id', '!=', $p->id)->pluck('price')->map(fn ($x) => (float) $x)->sort()->values()->all();
            $n = count($peers);

            if ($n < $c['min_peers']) {
                continue;
            }

            $median = $n % 2 ? $peers[intdiv($n, 2)] : ($peers[intdiv($n, 2) - 1] + $peers[intdiv($n, 2)]) / 2;
            $price = (float) $p->price;

            if ($median > 0 && ($price < $median * $c['low_ratio'] || $price > $median * $c['high_ratio'])) {
                $out[] = $p->id;
            }
        }

        sort($out);

        return $out;
    }

    public function test_the_price_rule_reads_the_median_of_the_other_listings_off_one_sorted_list(): void
    {
        $w = $this->operatorWorld();
        $odd = StoreCategory::query()->create(['name' => 'Odd peers', 'slug' => 'odd-peers']);
        $even = StoreCategory::query()->create(['name' => 'Even peers', 'slug' => 'even-peers']);

        // Odd and even numbers of peers, a listing that is itself in the list (approved) and one that is not (pending), the same price twice,
        // and cheap and dear outliers at both ends of the order.
        foreach ([10, 400, 410, 420, 430, 440, 450, 2900, 3000] as $price) {
            $this->makeListing($w['seller'], ['category_id' => $odd->id, 'price' => $price]);
        }

        foreach ([5, 400, 400, 410, 420, 430, 440, 450, 3100] as $price) {
            $this->makeListing($w['seller'], ['category_id' => $even->id, 'price' => $price]);
        }

        $this->makeListing($w['seller'], ['category_id' => $even->id, 'price' => 1, 'status' => 'pending_approval']);
        $this->makeListing($w['seller'], ['category_id' => $odd->id, 'price' => 9000, 'status' => 'pending_approval']);

        $got = app(ListingFlags::class)->all('price_anomaly')->map(fn ($f) => $f['product']->id)->sort()->values()->all();

        $this->assertNotEmpty($got);
        $this->assertSame($this->naivePriceFlags(), $got);
    }

    public function test_the_price_rule_costs_the_same_queries_for_few_and_many_listings(): void
    {
        $w = $this->operatorWorld();
        $category = StoreCategory::query()->create(['name' => 'Many', 'slug' => 'many']);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ListingFlags::class)->all('price_anomaly');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        foreach (range(1, 8) as $i) {
            $this->makeListing($w['seller'], ['category_id' => $category->id, 'price' => 100 + $i]);
        }

        $small = $count();

        foreach (range(1, 40) as $i) {
            $this->makeListing($w['seller'], ['category_id' => $category->id, 'price' => 200 + $i]);
        }

        $this->assertSame($small, $count());
    }

    public function test_a_request_that_wants_only_products_skips_the_facet_queries(): void
    {
        $w = $this->operatorWorld();
        $this->makeListing($w['seller'], ['name' => 'Facetless kettle']);
        app(SearchIndexer::class)->rebuild();
        $engine = app(SearchEngine::class);

        $with = $engine->search(SearchRequest::fromArray(['q' => 'kettle']));
        $without = $engine->search(SearchRequest::fromArray(['q' => 'kettle'])->with(['facets' => false]));

        $this->assertNotEmpty($with->facets);
        $this->assertSame([], $without->facets);
        $this->assertSame($with->productIds(), $without->productIds(), 'the products found do not change');
        $this->assertSame($with->total, $without->total);
        $this->assertNotSame(SearchRequest::fromArray(['q' => 'kettle'])->signature(), SearchRequest::fromArray(['q' => 'kettle'])->with(['facets' => false])->signature(), 'a cached answer with facets is never served to a caller that skipped them, or the other way round');
    }

    public function test_a_dynamic_collection_runs_without_the_facet_queries(): void
    {
        $w = $this->operatorWorld();
        $this->makeListing($w['seller'], ['name' => 'Collected pan', 'stock' => 5]);
        app(SearchIndexer::class)->rebuild();
        $collection = Collection::query()->create(['title' => 'Everything', 'slug' => 'everything', 'type' => Collection::DYNAMIC, 'rule' => ['sort' => 'newest'], 'visibility' => 'public', 'curator_id' => $w['operator']->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $products = app(Collections::class)->products($collection);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertNotEmpty($products);
        $this->assertStringNotContainsString('group by', strtolower($sql), 'no facet counts are computed for a list of products');
    }

    public function test_several_stores_ratings_come_from_one_query(): void
    {
        $ids = Store::factory()->count(6)->create()->pluck('id')->all();
        DB::table('store_rating_aggregates')->insert(['store_id' => $ids[0], 'average_rating' => 4.5, 'review_count' => 9, 'updated_at' => now()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summaries = app(StoreRatings::class)->summaries($ids);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame(4.5, $summaries[$ids[0]]['average']);
        $this->assertTrue($summaries[$ids[0]]['shown']);
        $this->assertNull($summaries[$ids[1]]['average'], 'a store with no reviews has no average');
        $this->assertSame(app(StoreRatings::class)->summary($ids[0]), $summaries[$ids[0]], 'the single-store answer is the same');
    }

    public function test_the_hot_path_indexes_exist_and_the_migration_can_be_run_again(): void
    {
        foreach ([
            ['order_groups', 'order_groups_store_status_index'],
            ['order_items', 'order_items_order_group_index'],
            ['orders', 'orders_created_at_index'],
            ['search_documents', 'search_documents_indexed_at_index'],
        ] as [$table, $index]) {
            $this->assertTrue(Schema::hasIndex($table, $index), "{$table}.{$index}");
        }

        $migration = require base_path('database/migrations/2026_11_12_000001_add_hot_path_indexes.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasIndex('orders', 'orders_created_at_index'));
    }

    // ---- card pictures ---------------------------------------------------------------------------------------------

    private function jpeg(int $width, int $height): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 60, 40));
        ob_start();
        imagejpeg($image, null, 90);

        return UploadedFile::fake()->createWithContent('photo.jpg', (string) ob_get_clean());
    }

    public function test_an_upload_also_stores_a_card_size_copy_and_removing_the_photo_removes_both(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);

        $image = app(ProductImages::class)->add($product, [$this->jpeg(1800, 1200)])->first();

        $this->assertNotNull($image->thumb_path);
        Storage::disk('public')->assertExists([$image->path, $image->thumb_path]);
        $this->assertSame(640, max(getimagesizefromstring(Storage::disk('public')->get($image->thumb_path))[0], getimagesizefromstring(Storage::disk('public')->get($image->thumb_path))[1]));
        $this->assertLessThan(strlen(Storage::disk('public')->get($image->path)), strlen(Storage::disk('public')->get($image->thumb_path)));
        $this->assertSame(Storage::disk('public')->url($image->thumb_path), $image->cardUrl());
        $this->assertSame(Storage::disk('public')->url($image->path), $image->url(), 'the product page still shows the full photo');

        app(ProductImages::class)->remove($image);

        Storage::disk('public')->assertMissing([$image->path, $image->thumb_path]);
    }

    public function test_a_photo_that_is_already_small_has_no_separate_card_copy_and_serves_itself(): void
    {
        Storage::fake('public');
        $product = $this->makeListing($this->makeSeller());

        $image = app(ProductImages::class)->add($product, [$this->jpeg(400, 400)])->first();

        $this->assertNull($image->thumb_path);
        $this->assertSame($image->url(), $image->cardUrl());
    }

    public function test_the_backfill_command_makes_the_missing_card_copies_and_can_be_run_again(): void
    {
        Storage::fake('public');
        $product = $this->makeListing($this->makeSeller());
        $big = app(ProductImages::class)->add($product, [$this->jpeg(1500, 1500)])->first();
        Storage::disk('public')->delete($big->thumb_path);
        $big->forceFill(['thumb_path' => null])->save();
        $small = app(ProductImages::class)->add($product, [$this->jpeg(300, 300)])->first();

        $this->artisan('emall:images-thumbnails')->assertSuccessful();

        $big->refresh();
        $this->assertNotNull($big->thumb_path);
        $this->assertNotSame($big->path, $big->thumb_path);
        Storage::disk('public')->assertExists($big->thumb_path);
        $this->assertSame($small->path, $small->fresh()->thumb_path, 'already card-sized: recorded, not copied');
        $before = ProductImage::query()->pluck('thumb_path', 'id')->all();

        $this->artisan('emall:images-thumbnails')->assertSuccessful();

        $this->assertSame($before, ProductImage::query()->pluck('thumb_path', 'id')->all());
    }
}
