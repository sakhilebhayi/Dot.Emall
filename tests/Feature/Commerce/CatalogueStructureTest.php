<?php

namespace Tests\Feature\Commerce;

use App\Models\Brand;
use App\Models\CategoryAttribute;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use App\Services\Catalogue\ProductAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class CatalogueStructureTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function tree(): CategoryTree
    {
        return app(CategoryTree::class);
    }

    public function test_categories_form_a_tree_with_materialised_paths_and_depth(): void
    {
        $root = $this->tree()->create(['name' => 'Electronics']);
        $child = $this->tree()->create(['name' => 'Laptops'], $root);
        $grand = $this->tree()->create(['name' => 'Gaming Laptops'], $child);

        $this->assertSame("/{$root->id}/", $root->path);
        $this->assertSame("/{$root->id}/{$child->id}/{$grand->id}/", $grand->path);
        $this->assertSame([0, 1, 2], [$root->depth, $child->depth, $grand->depth]);
        $this->assertEqualsCanonicalizing([$root->id, $child->id, $grand->id], $root->fresh()->subtreeIds());
        $this->assertSame(['Electronics', 'Laptops', 'Gaming Laptops'], array_map(fn ($c) => $c->name, $this->tree()->breadcrumbs($grand)));
    }

    public function test_slugs_are_unique_even_when_names_repeat(): void
    {
        $a = $this->tree()->create(['name' => 'Accessories']);
        $b = $this->tree()->create(['name' => 'Accessories']);

        $this->assertNotSame($a->slug, $b->slug);
    }

    public function test_moving_a_node_rewrites_the_paths_beneath_it_and_refuses_a_cycle(): void
    {
        $a = $this->tree()->create(['name' => 'A']);
        $b = $this->tree()->create(['name' => 'B']);
        $child = $this->tree()->create(['name' => 'Child'], $a);
        $grand = $this->tree()->create(['name' => 'Grand'], $child);

        $this->tree()->move($child, $b);

        $this->assertSame("/{$b->id}/{$child->id}/{$grand->id}/", $grand->fresh()->path);
        $this->assertSame(2, $grand->fresh()->depth);

        $this->expectException(InvalidArgumentException::class);
        $this->tree()->move($b->refresh(), $grand->fresh());
    }

    public function test_rollup_counts_include_descendants_and_only_count_listed_products(): void
    {
        $seller = $this->makeSeller();
        $root = $this->tree()->create(['name' => 'Home']);
        $kitchen = $this->tree()->create(['name' => 'Kitchen'], $root);
        $garden = $this->tree()->create(['name' => 'Garden'], $root);

        $this->makeListing($seller, ['category_id' => $kitchen->id]);
        $this->makeListing($seller, ['category_id' => $kitchen->id]);
        $this->makeListing($seller, ['category_id' => $garden->id]);
        // Not listed: pending moderation, switched off, archived, store closed.
        $this->makeListing($seller, ['category_id' => $kitchen->id, 'status' => 'pending_approval']);
        $this->makeListing($seller, ['category_id' => $kitchen->id, 'is_active' => false]);
        $this->makeListing($seller, ['category_id' => $garden->id])->delete();
        $closed = $this->makeSeller(['is_active' => false]);
        $this->makeListing($closed, ['category_id' => $garden->id]);

        $counts = $this->tree()->counts();

        $this->assertSame(2, $counts[$kitchen->id]['direct']);
        $this->assertSame(1, $counts[$garden->id]['direct']);
        $this->assertSame(3, $counts[$root->id]['rollup']);
        $this->assertSame(0, $counts[$root->id]['direct']);

        $nested = collect($this->tree()->tree())->firstWhere('id', $root->id);
        $this->assertSame(3, $nested['rollup_count']);
        $this->assertSame(['Garden', 'Kitchen'], collect($nested['children'])->pluck('name')->sort()->values()->all());
    }

    public function test_counts_follow_a_product_being_approved(): void
    {
        $seller = $this->makeSeller();
        $cat = $this->tree()->create(['name' => 'Toys']);
        $product = $this->makeListing($seller, ['category_id' => $cat->id, 'status' => 'pending_approval']);

        $this->assertArrayNotHasKey($cat->id, $this->tree()->counts());

        $product->update(['status' => 'approved']);

        $this->assertSame(1, $this->tree()->counts()[$cat->id]['direct']);
    }

    public function test_the_category_tree_costs_two_queries_however_deep(): void
    {
        $parent = null;
        foreach (range(1, 6) as $n) {
            $parent = $this->tree()->create(['name' => "Level {$n}"], $parent);
        }
        $this->tree()->counts(); // warm nothing: forget below
        CategoryTree::forgetCounts();

        DB::enableQueryLog();
        $this->tree()->tree();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries);
    }

    public function test_the_seed_command_builds_the_south_african_taxonomy_and_is_idempotent(): void
    {
        $this->artisan('emall:seed-categories')->assertSuccessful();

        $total = StoreCategory::query()->count();
        $roots = StoreCategory::query()->whereNull('parent_id')->pluck('slug')->all();

        foreach (['electronics-computers', 'phones', 'home-kitchen', 'appliances', 'furniture', 'garden-outdoor', 'diy-tools', 'fashion-women', 'fashion-men', 'fashion-kids', 'beauty', 'health', 'baby', 'toys-games', 'sports-outdoor', 'automotive', 'books-stationery', 'pets', 'groceries-pantry', 'office-school', 'art-crafts', 'farm-agri'] as $slug) {
            $this->assertContains($slug, $roots);
        }
        $this->assertGreaterThan(100, $total);
        $laptops = StoreCategory::query()->where('slug', 'laptops')->firstOrFail();
        $this->assertSame('electronics-computers', $laptops->parent->slug);
        $this->assertSame(1, $laptops->depth);

        $attributes = CategoryAttribute::query()->count();
        $this->artisan('emall:seed-categories')->assertSuccessful();

        $this->assertSame($total, StoreCategory::query()->count(), 'a second run creates nothing');
        $this->assertSame($attributes, CategoryAttribute::query()->count());
    }

    public function test_the_seed_command_never_deletes_or_renames_a_category_an_operator_added(): void
    {
        $custom = $this->tree()->create(['name' => 'Our Own Shelf']);
        $this->artisan('emall:seed-categories')->assertSuccessful();

        $this->assertSame('Our Own Shelf', $custom->fresh()->name);
    }

    public function test_laptops_get_spec_definitions_and_children_inherit_a_parents(): void
    {
        $this->artisan('emall:seed-categories')->assertSuccessful();

        $laptops = StoreCategory::query()->where('slug', 'laptops')->firstOrFail();
        $keys = app(ProductAttributes::class)->definitionsFor($laptops)->keys()->all();

        foreach (['ram', 'storage', 'processor', 'screen_size'] as $key) {
            $this->assertContains($key, $keys);
        }

        $clothing = StoreCategory::query()->where('slug', 'men-clothing')->firstOrFail();
        $this->assertEqualsCanonicalizing(['size', 'colour', 'material'], app(ProductAttributes::class)->definitionsFor($clothing)->keys()->all());

        $furnitureChild = StoreCategory::query()->where('slug', 'bedroom')->firstOrFail();
        $this->assertContains('assembly_required', app(ProductAttributes::class)->definitionsFor($furnitureChild)->keys()->all(), 'a child inherits its parent\'s definitions');
    }

    public function test_attribute_values_are_checked_against_the_category_definitions(): void
    {
        $this->artisan('emall:seed-categories')->assertSuccessful();
        $laptops = StoreCategory::query()->where('slug', 'laptops')->firstOrFail();
        $product = $this->makeListing($this->makeSeller(), ['category_id' => $laptops->id]);
        $service = app(ProductAttributes::class);

        $saved = $service->sync($product, ['ram' => '16', 'storage' => '512', 'processor' => 'Intel Core i5', 'operating_system' => 'Windows']);

        $this->assertEqualsCanonicalizing(['ram', 'storage', 'processor', 'operating_system'], $saved->pluck('key')->all());
        $ram = $saved->firstWhere('key', 'ram');
        $this->assertSame('16', $ram->value);
        $this->assertEquals(16, $ram->value_number);
        $this->assertSame('GB', $ram->unit);
        $this->assertTrue($ram->is_facetable);

        foreach ([['ram' => 'lots'], ['operating_system' => 'Plan 9'], ['colour' => 'red'], ['ram' => ['16']]] as $bad) {
            try {
                $service->sync($product, $bad);
                $this->fail('expected a validation error for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        // Dropping a key removes it.
        $service->sync($product, ['ram' => '8']);
        $this->assertSame(['ram'], $product->attributeValues()->pluck('key')->all());
    }

    public function test_a_required_attribute_must_be_given(): void
    {
        $cat = $this->tree()->create(['name' => 'Widgets']);
        CategoryAttribute::create(['category_id' => $cat->id, 'key' => 'grade', 'label' => 'Grade', 'type' => 'text', 'is_required' => true]);
        $product = $this->makeListing($this->makeSeller(), ['category_id' => $cat->id]);

        $this->expectException(ValidationException::class);
        app(ProductAttributes::class)->sync($product, []);
    }

    public function test_facet_counts_come_from_one_grouped_query(): void
    {
        $this->artisan('emall:seed-categories')->assertSuccessful();
        $laptops = StoreCategory::query()->where('slug', 'laptops')->firstOrFail();
        $seller = $this->makeSeller();
        $service = app(ProductAttributes::class);
        $ids = [];

        foreach ([['8', 'Windows'], ['16', 'Windows'], ['16', 'macOS']] as [$ram, $os]) {
            $p = $this->makeListing($seller, ['category_id' => $laptops->id]);
            $service->sync($p, ['ram' => $ram, 'operating_system' => $os]);
            $ids[] = $p->id;
        }

        DB::enableQueryLog();
        $facets = $service->facetCounts($ids);
        $this->assertCount(1, DB::getQueryLog());

        $this->assertSame(['16' => 2, '8' => 1], $facets['ram']);
        $this->assertSame(['Windows' => 2, 'macOS' => 1], $facets['operating_system']);
        $this->assertArrayNotHasKey('processor', $facets);
    }

    public function test_a_brand_is_matched_whatever_its_capitalisation(): void
    {
        $a = Brand::named('Samsung');
        $b = Brand::named('  samsung ');
        $c = Brand::named('SAMSUNG');

        $this->assertSame($a->id, $b->id);
        $this->assertSame($a->id, $c->id);
        $this->assertSame('Samsung', $a->name);

        $product = $this->makeListing($this->makeSeller(), ['brand_id' => $a->id]);
        $this->assertSame('Samsung', $product->fresh()->brand->name);
    }
}
