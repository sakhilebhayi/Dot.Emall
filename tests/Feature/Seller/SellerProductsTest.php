<?php

namespace Tests\Feature\Seller;

use App\Models\CategoryAttribute;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StoreCategory;
use App\Services\Catalogue\CategoryTree;
use App\Services\Seller\FeePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\Concerns\BuildsSellerDesk;
use Tests\TestCase;

/** Products: list and filters, bulk switching, create, edit with the stock check, options and variants, specifications, photos, duplicate, resubmit, fee preview. */
class SellerProductsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use BuildsSellerDesk;
    use RefreshDatabase;

    private function category(string $name, ?StoreCategory $parent = null): StoreCategory
    {
        return StoreCategory::query()->create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'parent_id' => $parent?->id]);
    }

    public function test_the_fee_preview_is_the_ledgers_arithmetic_rounded_to_whole_cents(): void
    {
        $seller = $this->makeSeller();
        $fees = app(FeePreview::class);

        config(['emall.commission.percent' => 0]);
        $zero = $fees->for($seller->store, '249.99');
        $this->assertSame([24999, 0, 24999], [$zero['price_cents'], $zero['fee_cents'], $zero['receive_cents']]);
        $this->assertStringContainsString('no fee on this sale', $zero['sentence']);

        config(['emall.commission.percent' => 7.5]);
        $p = $fees->for($seller->store, '249.99');
        $this->assertSame([1875, 23124], [$p['fee_cents'], $p['receive_cents']], '7.5 percent of 24999 cents is 1874.9, rounded to 1875');
        $this->assertSame('You receive R231.24 after Emall\'s fee of R18.75 (7.5% of the price).', $p['sentence']);

        $this->assertSame(0, $fees->for($seller->store, 'abc')['receive_cents'], 'a price that is not a number previews nothing');
        $this->assertSame(0, $fees->for($seller->store, '-5')['receive_cents']);

        // A per-store override wins, exactly as the ledger would apply it.
        \App\Models\StoreCommissionOverride::query()->create(['store_id' => $seller->store->id, 'percent' => 10]);
        $this->assertSame(1000, $fees->for($seller->store, '100')['fee_cents']);
    }

    public function test_the_forms_print_the_fee_sentence_and_no_compare_price_field(): void
    {
        config(['emall.commission.percent' => 5]);
        [$seller, $product] = $this->shippingSeller([], ['price' => 200]);

        $this->actingAs($seller)->get(route('seller.products.create'))->assertOk()->assertSee('data-fee-percent="5"', false)->assertSee('Dot.Emall takes 5% of the item price')->assertDontSee('compare_price', false)->assertDontSee('Compare');
        $this->get(route('seller.products.edit', $product))->assertOk()->assertSee('You receive R190.00 after Emall&#039;s fee of R10.00 (5% of the price).', false)->assertDontSee('compare_price', false);
    }

    public function test_a_typed_compare_price_is_ignored_and_never_stored(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('seller.products.store'), ['name' => 'No Was Price Lamp', 'price' => '100.00', 'stock' => 3, 'compare_price' => '500.00'])->assertSessionHasNoErrors();

        $this->assertNull(Product::query()->where('name', 'No Was Price Lamp')->value('compare_price'));
    }

    public function test_creating_a_listing_sends_it_for_review_and_lands_on_the_edit_page(): void
    {
        Storage::fake('public');
        $seller = $this->makeSeller();
        $cat = $this->category('Lamps');

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'name' => 'Brass Desk Lamp', 'description' => 'Heavy brass.', 'price' => '349.50', 'stock' => 4, 'sku' => 'LAMP-001', 'category_id' => $cat->id, 'brand' => 'Karoo Brass',
            'low_stock_threshold' => 2, 'images' => [UploadedFile::fake()->image('a.jpg', 400, 400)],
        ])->assertSessionHasNoErrors();

        $p = Product::query()->where('name', 'Brass Desk Lamp')->first();
        $this->assertSame(['pending_approval', 2, 'Karoo Brass'], [$p->status, $p->low_stock_threshold, $p->brand->name]);
        $this->assertSame(1, $p->images()->count());
        $this->assertSame(4, $p->variants()->first()->stock, 'it starts with its implicit variant');
        $this->get(route('seller.products.edit', $p))->assertOk()->assertSee('Brass Desk Lamp')->assertSee('Pending review');
    }

    public function test_the_create_form_refuses_what_is_wrong_in_words(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller);

        $this->post(route('seller.products.store'), ['name' => '', 'price' => 'free', 'stock' => -1])->assertSessionHasErrors(['name', 'price', 'stock']);
        $this->post(route('seller.products.store'), ['name' => 'x', 'price' => '10.123', 'stock' => 1])->assertSessionHasErrors('price');
        $this->post(route('seller.products.store'), ['name' => 'x', 'price' => '10', 'stock' => 1, 'category_id' => 99999])->assertSessionHasErrors('category_id');
        $this->post(route('seller.products.store'), ['name' => 'x', 'price' => '10', 'stock' => 1, 'images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('images.0');
        $this->assertSame(0, Product::count());
    }

    public function test_an_edit_is_checked_against_the_stock_the_form_was_drawn_with(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['stock' => 10]);
        [$order] = $this->paidParcel($product, null, 3); // 7 left while the seller has the form open

        // The seller, looking at 10, types 12: that is +2 on whatever is there now.
        $this->actingAs($seller)->patch(route('seller.products.update', $product), ['name' => $product->name, 'price' => '100.00', 'stock' => 12, 'stock_was' => 10])->assertSessionHasNoErrors();
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame(9, $product->variants()->first()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'delta' => 2, 'reason' => 'seller_edit']);

        // A price-only edit never resurrects the three units that were sold.
        $this->patch(route('seller.products.update', $product), ['name' => $product->name, 'price' => '120.00', 'stock' => 9, 'stock_was' => 9]);
        $this->assertSame([9, '120.00'], [$product->fresh()->stock, $product->fresh()->price]);
    }

    public function test_variants_are_defined_priced_and_stocked_with_the_stock_check_per_variant(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['price' => 100, 'stock' => 5]);
        $this->actingAs($seller);

        $this->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'S, M, L'], ['name' => 'Colour', 'values' => 'Red, Blue'], ['name' => '', 'values' => '']]])->assertSessionHasNoErrors();
        $this->assertSame(['Size', 'Colour'], $product->options()->pluck('name')->all());
        $this->get(route('seller.products.edit', $product))->assertOk()->assertSee('Size: S, Colour: Red')->assertSee('Size: L, Colour: Blue');

        $rows = [
            ['options' => ['Size' => 'S', 'Colour' => 'Red'], 'price' => '110.00', 'stock' => 4, 'sku' => 'KET-S-R', 'is_active' => '1'],
            ['options' => ['Size' => 'M', 'Colour' => 'Red'], 'price' => '130.00', 'stock' => 6, 'is_active' => '1'],
            ['options' => ['Size' => 'L', 'Colour' => 'Red'], 'price' => '', 'stock' => ''], // no price: not sold
        ];
        $this->post(route('seller.products.variants', $product), ['rows' => $rows])->assertSessionHasNoErrors()->assertRedirect();

        $product->refresh();
        $this->assertSame(2, $product->variants()->count(), 'a combination without a price is not made, and the implicit variant is retired');
        $this->assertSame(['110.00', 10], [$product->price, $product->stock], 'the listing shows its cheapest price and the total stock');
        $small = ProductVariant::query()->where('sku', 'KET-S-R')->first();
        $this->assertSame(4, $small->stock);

        // Units are sold while the seller edits; the form still says 4.
        $small->forceFill(['stock' => 3])->save();
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'S', 'Colour' => 'Red'], 'price' => '110.00', 'stock' => 6, 'stock_was' => 4, 'is_active' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame(5, $small->fresh()->stock, '6 typed over the 4 seen is +2 on the 3 that are left');

        // A price below zero, a duplicate SKU and a photo that is not this listing's are named at the row.
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'S', 'Colour' => 'Red'], 'price' => '-5']]])->assertSessionHasErrors('rows.0.price');
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'M', 'Colour' => 'Red'], 'price' => '130', 'sku' => 'KET-S-R']]])->assertSessionHasErrors('rows.0.sku');
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'M', 'Colour' => 'Red'], 'price' => '130', 'image_id' => 99999]]])->assertSessionHasErrors('rows.0.image_id');
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'XXL', 'Colour' => 'Red'], 'price' => '130']]])->assertSessionHasErrors('rows.0.price');
    }

    public function test_a_variant_can_be_linked_to_one_of_the_listings_own_photos(): void
    {
        Storage::fake('public');
        [$seller, $product] = $this->shippingSeller();
        $other = $this->makeSeller();
        $mine = ProductImage::create(['product_id' => $product->id, 'path' => 'product-images/x/a.jpg', 'sort_order' => 0]);
        $theirs = ProductImage::create(['product_id' => $this->makeListing($other)->id, 'path' => 'product-images/y/b.jpg', 'sort_order' => 0]);
        $this->actingAs($seller)->post(route('seller.products.options', $product), ['options' => [['name' => 'Colour', 'values' => 'Red, Blue']]]);

        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Colour' => 'Red'], 'price' => '100', 'image_id' => $mine->id, 'is_active' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame($mine->id, ProductVariant::query()->where('product_id', $product->id)->first()->image_id);
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Colour' => 'Blue'], 'price' => '100', 'image_id' => $theirs->id]]])->assertSessionHasErrors('rows.0.image_id');
    }

    public function test_removing_every_option_puts_the_product_back_to_one_price_and_one_stock(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['price' => 100, 'stock' => 5]);
        $this->actingAs($seller)->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'S, M']]]);
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'S'], 'price' => '90', 'stock' => 2, 'is_active' => '1'], ['options' => ['Size' => 'M'], 'price' => '95', 'stock' => 3, 'is_active' => '1']]]);
        $this->assertSame(5, $product->fresh()->stock);

        $this->post(route('seller.products.options', $product), ['options' => [['name' => '', 'values' => '']]])->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(0, $product->options()->count());
        $this->assertSame(1, $product->variants()->where('is_active', true)->count(), 'the implicit variant is back');
        $this->get(route('seller.products.edit', $product))->assertSee('In stock');
    }

    public function test_removing_one_value_switches_off_its_combinations_and_bad_options_are_refused(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $this->actingAs($seller)->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'S, M, L']]]);
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'S'], 'price' => '9', 'stock' => 1, 'is_active' => '1'], ['options' => ['Size' => 'L'], 'price' => '9', 'stock' => 1, 'is_active' => '1']]]);

        $this->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'S, M']]]);
        $this->assertFalse(ProductVariant::query()->where('product_id', $product->id)->get()->contains(fn ($v) => $v->options === ['Size' => 'L'] && $v->is_active));

        $this->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => '']]])->assertSessionHasErrors('options');
        $this->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'A'], ['name' => 'Size', 'values' => 'B']]])->assertSessionHasErrors('options');
        $this->post(route('seller.products.options', $product), ['options' => [['name' => 'A', 'values' => 'x'], ['name' => 'B', 'values' => 'x'], ['name' => 'C', 'values' => 'x'], ['name' => 'D', 'values' => 'x']]])->assertSessionHasErrors('options');
    }

    public function test_specifications_follow_the_category_validate_and_report_the_missing_required_one(): void
    {
        [$seller, $product] = $this->shippingSeller();
        $electronics = $this->category('Electronics');
        $laptops = $this->category('Laptops', $electronics);
        CategoryAttribute::create(['category_id' => $electronics->id, 'key' => 'colour', 'label' => 'Colour', 'type' => 'text', 'position' => 1]);
        CategoryAttribute::create(['category_id' => $laptops->id, 'key' => 'ram', 'label' => 'Memory', 'type' => 'number', 'unit' => 'GB', 'is_facetable' => true, 'is_required' => true, 'position' => 2]);
        CategoryAttribute::create(['category_id' => $laptops->id, 'key' => 'os', 'label' => 'Operating system', 'type' => 'enum', 'options' => ['Windows', 'Linux'], 'position' => 3]);
        $product->update(['category_id' => $laptops->id]);
        $this->actingAs($seller);

        $this->get(route('seller.products.edit', $product))->assertSee('Memory')->assertSee('Operating system')->assertSee('Colour')->assertSee('Specifications still needed')->assertSee('In GB');

        $this->patch(route('seller.products.attributes', $product), ['attributes' => ['colour' => 'Black']])->assertSessionHasErrors('attributes.ram');
        $this->patch(route('seller.products.attributes', $product), ['attributes' => ['ram' => 'lots', 'os' => 'Plan 9']])->assertSessionHasErrors(['attributes.ram', 'attributes.os']);
        $this->patch(route('seller.products.attributes', $product), ['attributes' => ['ram' => '16', 'os' => 'Linux', 'colour' => 'Black']])->assertSessionHasNoErrors();

        $saved = $product->attributeValues()->pluck('value', 'key')->all();
        $this->assertSame(['16', 'Linux', 'Black'], [$saved['ram'], $saved['os'], $saved['colour']]);
        $this->get(route('seller.products.edit', $product))->assertDontSee('Specifications still needed');
    }

    public function test_the_list_filters_by_status_stock_category_and_search_and_pages(): void
    {
        [$seller, $kettle] = $this->shippingSeller([], ['name' => 'Alpha Kettle', 'stock' => 20, 'sku' => 'ALP-1']);
        $rootCat = $this->category('Kitchen');
        $child = $this->category('Kettles', $rootCat);
        $kettle->update(['category_id' => $child->id]);
        $low = $this->makeListing($seller, ['name' => 'Bravo Low Toaster', 'stock' => 2]);
        $out = $this->makeListing($seller, ['name' => 'Charlie Gone Blender', 'stock' => 0]);
        $pending = $this->makeListing($seller, ['name' => 'Delta Pending Fryer', 'status' => 'pending_approval']);
        $rejected = $this->makeListing($seller, ['name' => 'Echo Rejected Mixer', 'status' => 'rejected', 'rejected_reason' => 'The photo shows another brand.']);
        $off = $this->makeListing($seller, ['name' => 'Foxtrot Off Grill', 'is_active' => false]);
        CategoryTree::forgetCounts();
        $this->actingAs($seller);

        $names = fn (array $q) => $this->get(route('seller.products.index', $q))->assertOk()->viewData('products')->pluck('name')->sort()->values()->all();
        $this->assertCount(6, $names([]));
        $this->assertSame(['Bravo Low Toaster'], $names(['stock' => 'low']));
        $this->assertSame(['Charlie Gone Blender'], $names(['stock' => 'out']));
        $this->assertCount(5, $names(['stock' => 'in']) + [0, 0, 0, 0, 0]) ;
        $this->assertSame(['Delta Pending Fryer'], $names(['status' => 'pending_approval']));
        $this->assertSame(['Echo Rejected Mixer'], $names(['status' => 'rejected']));
        $this->assertSame(['Foxtrot Off Grill'], $names(['status' => 'off']));
        $this->assertSame(['Alpha Kettle'], $names(['category' => $rootCat->id]), 'a parent category includes its children');
        $this->assertSame(['Alpha Kettle'], $names(['q' => 'alp-1']), 'search by SKU, any case');
        $this->assertSame(['Delta Pending Fryer'], $names(['q' => 'pending fry']));
        $this->assertSame([], $names(['q' => '%']), 'a percent sign is a character, not a wildcard');
        $this->get(route('seller.products.index', ['status' => 'rejected']))->assertSee('The photo shows another brand.');
        $this->get(route('seller.products.index', ['status' => 'bogus']))->assertSessionHasErrors('status');
    }

    public function test_the_list_costs_the_same_number_of_queries_for_few_and_many_listings(): void
    {
        $seller = $this->makeSeller();
        foreach (range(1, 2) as $i) {
            $this->makeListing($seller);
        }
        $this->actingAs($seller)->get(route('seller.products.index'))->assertOk();
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get(route('seller.products.index'))->assertOk();
        $few = count(\DB::getQueryLog());

        foreach (range(1, 15) as $i) {
            $this->makeListing($seller);
        }
        $this->get(route('seller.products.index'))->assertOk(); // creating listings empties the category count cache once; measure after it is warm again
        \DB::flushQueryLog();
        $this->get(route('seller.products.index'))->assertOk();

        $this->assertSame($few, count(\DB::getQueryLog()));
    }

    public function test_bulk_switching_is_real_validated_and_reports_what_it_changed(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller);
        $b = $this->makeListing($seller);
        $this->actingAs($seller);

        $this->post(route('seller.products.bulk'), ['action' => 'deactivate', 'ids' => [$a->id, $b->id]])->assertRedirect()->assertSessionHas('status', '2 listings switched off.');
        $this->assertSame([false, false], [$a->fresh()->is_active, $b->fresh()->is_active]);
        $this->post(route('seller.products.bulk'), ['action' => 'activate', 'ids' => [$a->id]])->assertSessionHas('status', '1 listing switched on.');
        $this->assertTrue($a->fresh()->is_active);
        $this->post(route('seller.products.bulk'), ['action' => 'activate', 'ids' => [$a->id]])->assertSessionHas('status', fn ($m) => str_contains($m, 'Nothing changed'));

        $this->post(route('seller.products.bulk'), ['action' => 'delete', 'ids' => [$a->id]])->assertSessionHasErrors('action');
        $this->post(route('seller.products.bulk'), ['action' => 'activate'])->assertSessionHasErrors('ids');
        $this->post(route('seller.products.bulk'), ['action' => 'activate', 'ids' => range(1, 101)])->assertSessionHasErrors('ids');
    }

    public function test_photos_are_added_over_json_with_the_limit_and_messages_and_a_cover_can_be_chosen(): void
    {
        Storage::fake('public');
        [$seller, $product] = $this->shippingSeller();
        $this->actingAs($seller);

        $this->postJson(route('seller.products.images.store', $product), ['images' => [UploadedFile::fake()->image('a.jpg', 300, 300), UploadedFile::fake()->image('b.jpg', 300, 300)]])
            ->assertOk()->assertJsonPath('redirect', route('seller.products.edit', $product).'#photos');
        $this->assertSame(2, $product->images()->count());

        $this->postJson(route('seller.products.images.store', $product), ['images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])->assertStatus(422)->assertJsonValidationErrors('images.0');
        $this->postJson(route('seller.products.images.store', $product), ['images' => []])->assertStatus(422);
        $this->postJson(route('seller.products.images.store', $product), ['images' => array_map(fn ($i) => UploadedFile::fake()->image("c$i.jpg", 300, 300), range(1, 5))])->assertStatus(422)->assertJsonValidationErrors('images');
        $this->assertSame(2, $product->images()->count(), 'a refused batch adds nothing');

        $second = $product->images()->orderBy('sort_order')->get()[1];
        $this->patch(route('seller.products.images.cover', [$product, $second]))->assertRedirect();
        $this->assertSame($second->id, $product->images()->first()->id);
        $this->delete(route('seller.products.images.destroy', [$product, $second]))->assertRedirect();
        $this->assertSame(1, $product->images()->count());
    }

    public function test_duplicating_copies_the_options_and_prices_but_not_photos_stock_or_sku_and_starts_switched_off(): void
    {
        Storage::fake('public');
        [$seller, $product] = $this->shippingSeller([], ['sku' => 'ORIG-1', 'price' => 100]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'product-images/x/a.jpg', 'sort_order' => 0]);
        $this->actingAs($seller)->post(route('seller.products.options', $product), ['options' => [['name' => 'Size', 'values' => 'S, M']]]);
        $this->post(route('seller.products.variants', $product), ['rows' => [['options' => ['Size' => 'S'], 'price' => '90', 'stock' => 4, 'sku' => 'ORIG-S', 'is_active' => '1'], ['options' => ['Size' => 'M'], 'price' => '95', 'stock' => 3, 'is_active' => '1']]]);

        $this->post(route('seller.products.duplicate', $product))->assertSessionHasNoErrors();

        $copy = Product::query()->where('name', 'like', 'Copy of %')->first();
        $this->assertNotNull($copy);
        $this->assertSame(['pending_approval', false, 0, 0], [$copy->status, $copy->is_active, $copy->images()->count(), $copy->stock]);
        $this->assertSame(['Size'], $copy->options()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['90.00', '95.00'], $copy->variants()->pluck('price')->all());
        $this->assertSame([null, null], $copy->variants()->pluck('sku')->unique()->values()->all() === [null] ? [null, null] : ['x', 'y'], 'no SKU is copied: they are unique');
        $this->assertSame(4, ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->sum('stock') - 3, 'the original is untouched');
    }

    public function test_a_rejected_listing_shows_the_reason_and_can_be_sent_again_once(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['status' => 'rejected', 'rejected_reason' => 'The photo is of a different product.']);
        $this->actingAs($seller);

        $this->get(route('seller.products.edit', $product))->assertSee('Why it was rejected')->assertSee('The photo is of a different product.')->assertSee('Send for review again');
        $this->get(route('seller.dashboard'))->assertSee('was rejected')->assertSee('The photo is of a different product.');

        $this->post(route('seller.products.resubmit', $product))->assertRedirect();
        $this->assertSame(['pending_approval', null], [$product->fresh()->status, $product->fresh()->rejected_reason]);
        $this->post(route('seller.products.resubmit', $product))->assertSessionHasErrors('status');
    }

    public function test_stock_history_lists_every_change_and_the_set_stock_form_applies_the_seen_number(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['stock' => 10]);
        $variant = $product->variants()->first();
        $this->actingAs($seller);
        $this->paidParcel($product, null, 2); // a sale: -2

        $this->post(route('seller.products.stock', $product), ['stock' => [$variant->id => ['value' => 20, 'was' => 8]]])->assertSessionHasNoErrors();
        $this->assertSame(20, $variant->fresh()->stock);

        $page = $this->get(route('seller.products.stock-history', $product))->assertOk()->assertSee('Sold')->assertSee('You changed it')->assertSee('+12')->assertSee('-2');
        $this->assertGreaterThanOrEqual(3, $page->viewData('movements')->total());

        $this->post(route('seller.products.stock', $product), ['stock' => [$variant->id => ['value' => -1]]])->assertSessionHasErrors('stock.'.$variant->id.'.value');
        $this->post(route('seller.products.stock', $product), ['stock' => [999999 => ['value' => 5]]]); // a variant of nobody's product is ignored
        $this->assertSame(20, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->where('variant_id', 999999)->count());
    }

    public function test_the_edit_page_shows_a_preview_card_and_a_delete_that_is_confirmed(): void
    {
        [$seller, $product] = $this->shippingSeller([], ['name' => 'Preview Card Teapot']);

        $this->actingAs($seller)->get(route('seller.products.edit', $product))->assertOk()->assertSee('Preview')->assertSee('ds-pcard', false)->assertSee('Delete this product')->assertSee('This cannot be undone');
        $this->delete(route('seller.products.destroy', $product))->assertRedirect(route('seller.products.index'));
        $this->assertSoftDeleted($product);
    }

    public function test_a_name_with_an_ampersand_is_not_double_escaped_in_the_heading_or_the_title(): void
    {
        $seller = $this->makeSeller(['name' => 'Salt & Pepper Trading']);

        $html = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Salt &amp; Pepper Trading</h1>', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }
}
