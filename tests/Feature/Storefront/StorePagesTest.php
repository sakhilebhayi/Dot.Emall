<?php

namespace Tests\Feature\Storefront;

use App\Models\ContentReport;
use App\Notifications\StoreEnquiryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsStorefront;
use Tests\TestCase;

class StorePagesTest extends TestCase
{
    use BuildsMarketplace;
    use BuildsStorefront;
    use RefreshDatabase;

    private function store(array $attributes = [], int $listings = 1): array
    {
        $seller = $this->makeSeller(array_merge([
            'name' => 'Karoo Pottery Distinct', 'description' => 'Hand thrown pots from Prince Albert.', 'region' => 'Western Cape', 'city' => 'Prince Albert',
            'contact_email' => 'secret-owner@private-store.test', 'contact_phone' => '0215550101', 'legal_name' => 'Karoo Pottery Holdings (Pty) Ltd',
            'registration_number' => '2019/123456/07', 'vat_number' => '4999888777', 'vat_registered' => true,
        ], $attributes));

        for ($i = 0; $i < $listings; $i++) {
            $this->makeListing($seller, ['name' => 'Glazed Bowl '.$i.' Distinct', 'price' => 100 + $i, 'stock' => 4]);
        }

        return [$seller, $seller->store];
    }

    public function test_the_directory_lists_stores_that_have_something_for_sale_and_filters_by_region_and_name(): void
    {
        [, $a] = $this->store();
        [, $b] = $this->store(['name' => 'Durban Tools Distinct', 'region' => 'KwaZulu-Natal', 'city' => 'Durban', 'description' => 'Spanners']);
        $empty = $this->makeSeller(['name' => 'Nothing For Sale Store'])->store;

        $this->get(route('stores.index'))->assertOk()->assertSee('Karoo Pottery Distinct')->assertSee('Durban Tools Distinct')->assertDontSee('Nothing For Sale Store');
        $this->get(route('stores.index', ['region' => 'KwaZulu-Natal']))->assertSee('Durban Tools Distinct')->assertDontSee('Karoo Pottery Distinct');
        $this->get(route('stores.index', ['q' => 'pottery']))->assertSee('Karoo Pottery Distinct')->assertDontSee('Durban Tools Distinct');
        $this->get(route('stores.index', ['q' => 'zzzz']))->assertOk()->assertSee('No stores match');
    }

    public function test_the_directory_and_store_page_show_no_private_seller_data(): void
    {
        [$seller, $store] = $this->store();

        foreach ([route('stores.index'), route('stores.show', $store)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            foreach (['secret-owner@private-store.test', '0215550101', 'Karoo Pottery Holdings', $seller->email, 'Pty) Ltd'] as $private) {
                $this->assertStringNotContainsString($private, $html, "{$private} leaked on {$url}");
            }
        }

        $page = $this->get(route('stores.show', $store))->getContent();
        $this->assertStringContainsString('claimed by the seller, not checked', $page, 'claimed numbers are labelled as claimed');
    }

    public function test_a_store_page_shows_identity_trust_policies_products_and_category_chips(): void
    {
        [, $store] = $this->store(['shipping_policy' => 'We ship on Mondays.', 'return_policy' => 'Unused bowls come back.'], 3);

        $this->get(route('stores.show', $store))->assertOk()
            ->assertSee('Karoo Pottery Distinct')->assertSee('Prince Albert')->assertSee('About this seller')->assertSee('We ship on Mondays.')->assertSee('Unused bowls come back.')
            ->assertSee('Glazed Bowl 0 Distinct')->assertSee('Glazed Bowl 2 Distinct')->assertSee('Write to the store')->assertSee('Not enough orders yet')
            ->assertSee('No reviews yet');
    }

    public function test_the_store_product_grid_is_scoped_to_the_store_whatever_the_query_says(): void
    {
        [, $store] = $this->store();
        [, $other] = $this->store(['name' => 'Other Store Distinct'], 1);
        $this->makeListing($other->owner, ['name' => 'Foreign Item Distinct', 'stock' => 3]);

        $html = $this->get(route('stores.show', $store).'?store[]='.$other->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('Foreign Item Distinct', $html);
        $this->assertStringContainsString('Glazed Bowl 0 Distinct', $html);

        $this->get(route('stores.show', $store).'?q=bowl&sort=price_desc&in_stock=1')->assertOk()->assertSee('Glazed Bowl 0 Distinct');
    }

    public function test_a_closed_store_is_a_404_for_the_public_and_visible_to_its_owner(): void
    {
        [$seller, $store] = $this->store(['is_active' => false]);

        $this->get(route('stores.show', $store))->assertNotFound();
        $this->actingAs($seller)->get(route('stores.show', $store))->assertOk()->assertSee('This store is closed');
        $this->get(route('stores.show', 'no-such-store'))->assertNotFound();
    }

    public function test_the_store_uses_its_slug_in_links_not_its_id(): void
    {
        [, $store] = $this->store();

        $this->assertStringContainsString('/stores/'.$store->slug, route('stores.show', $store));
        $this->get('/stores/'.$store->id)->assertNotFound();
    }

    public function test_a_signed_in_shopper_writes_to_the_store_and_the_store_never_exposes_its_address(): void
    {
        Notification::fake();
        [$seller, $store] = $this->store();
        $buyer = $this->makeBuyer();

        $this->get(route('stores.show', $store))->assertSee('to write to the store');
        $this->actingAs($buyer)->get(route('stores.show', $store))->assertSee('We do not show you the store')->assertDontSee('secret-owner@private-store.test');

        $this->actingAs($buyer)->post(route('stores.message', $store), ['body' => 'Do you make bowls in other colours?'])->assertRedirect()->assertSessionHas('status');

        Notification::assertSentTo($seller, StoreEnquiryNotification::class, function ($n) use ($buyer) {
            $data = $n->toDatabase($buyer);

            return str_contains($data['message'], $buyer->email) && str_contains($data['message'], 'other colours');
        });
    }

    public function test_the_message_form_validates_refuses_guests_and_is_rate_limited(): void
    {
        Notification::fake();
        [, $store] = $this->store();
        $buyer = $this->makeBuyer();

        $this->post(route('stores.message', $store), ['body' => 'Hello there, store'])->assertRedirect(route('login'));
        $this->actingAs($buyer)->post(route('stores.message', $store), ['body' => 'short'])->assertSessionHasErrors('body');
        $this->actingAs($buyer)->post(route('stores.message', $store), ['body' => str_repeat('x', 1001)])->assertSessionHasErrors('body');

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->actingAs($buyer)->post(route('stores.message', $store), ['body' => "Message number {$i} to the store"])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'six messages an hour is the limit');
    }

    public function test_the_stores_own_people_cannot_message_their_own_store(): void
    {
        [$seller, $store] = $this->store();

        $this->actingAs($seller)->post(route('stores.message', $store), ['body' => 'Writing to myself here'])->assertSessionHasErrors('body', null, 'enquiry');
    }

    public function test_a_store_can_be_reported_once_and_not_by_its_owner(): void
    {
        [$seller, $store] = $this->store();
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->post(route('stores.report', $store), ['reason' => 'fake', 'details' => 'Sells copies'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, ContentReport::where('reportable_type', 'store')->where('reportable_id', $store->id)->count());
        $this->actingAs($buyer)->post(route('stores.report', $store), ['reason' => 'fake'])->assertSessionHasErrors('reason', null, 'reportstore'.$store->id);
        $this->actingAs($seller)->post(route('stores.report', $store), ['reason' => 'spam'])->assertSessionHasErrors('reason', null, 'reportstore'.$store->id);
        $this->assertSame(1, ContentReport::count());
        $this->app['auth']->forgetGuards();
        $this->post(route('stores.report', $store), ['reason' => 'spam'])->assertRedirect(route('login'));
    }

    public function test_a_listing_can_be_reported_once_and_not_by_its_own_store(): void
    {
        [$seller, $store] = $this->store();
        $product = $store->products()->first();
        $buyer = $this->makeBuyer();

        $this->actingAs($buyer)->post(route('products.report', $product), ['reason' => 'fake'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, ContentReport::where('reportable_type', 'product')->count());
        $this->actingAs($seller)->post(route('products.report', $product), ['reason' => 'fake'])->assertSessionHasErrors('reason', null, 'reportproduct'.$product->id);
        $this->actingAs($buyer)->post(route('products.report', $product), ['reason' => 'unheard'])->assertSessionHasErrors('reason', null, 'reportproduct'.$product->id);
    }

    public function test_the_directory_does_not_scale_its_queries_with_the_number_of_stores(): void
    {
        $this->store(['name' => 'First Store Distinct']);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('stores.index'))->assertOk();

            return count(DB::getQueryLog());
        };
        $few = $count();

        foreach (range(1, 8) as $n) {
            $this->store(['name' => "Another Store {$n} Distinct"]);
        }

        $this->assertLessThanOrEqual($few + 1, $count());
    }
}
