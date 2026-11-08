<?php

namespace Tests\Feature\Discovery;

use App\Models\SavedSearch;
use App\Models\WishlistItem;
use App\Services\Discovery\SavedSearches;
use App\Services\Discovery\Wishlists;
use App\Services\Search\SearchRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsCatalogue;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use BuildsCatalogue;
    use BuildsMarketplace;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCatalogue();
    }

    public function test_adding_is_idempotent_and_remembers_the_price_seen(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['price' => 250.00]);
        $lists = app(Wishlists::class);

        $first = $lists->add($user, $product, null, 'for mom');
        $second = $lists->add($user, $product);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WishlistItem::count());
        $this->assertSame('250.00', $first->price_when_added);
        $this->assertSame('for mom', $second->fresh()->note);
        $this->assertTrue($lists->has($user, $product));
    }

    public function test_a_variant_is_a_separate_entry(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller());
        $lists = app(Wishlists::class);

        $lists->add($user, $product, 11);
        $lists->add($user, $product, 12);
        $lists->add($user, $product, 11);

        $this->assertSame(2, WishlistItem::count());
    }

    public function test_products_that_cannot_be_bought_cannot_be_saved(): void
    {
        $user = $this->makeBuyer();
        $pending = $this->makeListing($this->makeSeller(), ['status' => 'pending_approval']);

        $this->expectException(ValidationException::class);
        app(Wishlists::class)->add($user, $pending);
    }

    public function test_a_list_is_private_until_shared_and_the_link_can_be_revoked(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller());
        $lists = app(Wishlists::class);
        $lists->add($user, $product, null, 'secret note');
        $list = $lists->defaultFor($user);

        $this->assertNull($list->share_token, 'sharing is off by default');
        $this->assertNull($lists->shared(str_repeat('a', 40)));

        $token = $lists->enableSharing($list);
        $shared = $lists->shared($token);

        $this->assertSame([$product->id], $shared['products']->pluck('id')->all());
        $this->assertStringNotContainsString('secret note', json_encode($shared['products']->toArray()));

        $lists->disableSharing($list);
        $this->assertNull($lists->shared($token));

        $new = $lists->enableSharing($list);
        $this->assertNotSame($token, $new);
    }

    public function test_a_shared_list_shows_only_what_can_be_bought(): void
    {
        $user = $this->makeBuyer();
        $seller = $this->makeSeller();
        $good = $this->makeListing($seller);
        $gone = $this->makeListing($seller);
        $lists = app(Wishlists::class);
        $lists->add($user, $good);
        $lists->add($user, $gone);
        $token = $lists->enableSharing($lists->defaultFor($user));

        $gone->update(['is_active' => false]);

        $this->assertSame([$good->id], $lists->shared($token)['products']->pluck('id')->all());
    }

    public function test_the_list_flags_what_is_no_longer_available_instead_of_dropping_it(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 3]);
        app(Wishlists::class)->add($user, $product);
        $product->update(['stock' => 0]);

        $item = app(Wishlists::class)->items($user)->first();

        $this->assertFalse($item->available);
    }

    public function test_move_to_cart_respects_stock_and_removes_the_item(): void
    {
        $user = $this->makeBuyer();
        $product = $this->makeListing($this->makeSeller(), ['stock' => 2]);
        $lists = app(Wishlists::class);
        $item = $lists->add($user, $product);

        $result = $lists->moveToCart($user, $item);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, (int) DB::table('cart_items')->where('user_id', $user->id)->value('quantity'));
        $this->assertSame(0, WishlistItem::count());

        $product->update(['stock' => 0]);
        $again = $lists->add($user, $product->fresh());
        $this->assertFalse($lists->moveToCart($user, $again)['ok']);
    }

    public function test_the_list_has_a_size_limit(): void
    {
        config(['discovery.wishlist.max_items' => 2]);
        $user = $this->makeBuyer();
        $seller = $this->makeSeller();
        $lists = app(Wishlists::class);
        $lists->add($user, $this->makeListing($seller));
        $lists->add($user, $this->makeListing($seller));

        $this->expectException(ValidationException::class);
        $lists->add($user, $this->makeListing($seller));
    }

    public function test_other_people_cannot_touch_a_list(): void
    {
        $owner = $this->makeBuyer();
        $stranger = $this->makeBuyer();
        $item = app(Wishlists::class)->add($owner, $this->makeListing($this->makeSeller()));

        $this->assertTrue($owner->can('update', $item->wishlist));
        $this->assertFalse($stranger->can('update', $item->wishlist));
        $this->assertFalse($stranger->can('delete', $item->wishlist));
    }

    public function test_a_saved_search_remembers_what_it_already_showed_and_reports_only_new_matches(): void
    {
        $user = $this->makeBuyer();
        $store = $this->store();
        $this->listing($store, 'Garden Hose 20m', 250.0);
        $this->reindex();

        $saved = app(SavedSearches::class)->save($user, 'Hoses', new SearchRequest(text: 'hose', priceMax: 500.0), 'daily');

        $this->assertSame([], app(SavedSearches::class)->newMatches($saved));

        $new = $this->listing($store, 'Heavy Duty Hose 50m', 400.0);
        $this->reindex();

        $this->assertSame([$new->id], app(SavedSearches::class)->newMatches($saved->fresh()));
        $this->assertSame('hose', $saved->toRequest()->text);
        $this->assertSame(500.0, $saved->toRequest()->priceMax);
    }

    public function test_saving_validates_name_frequency_and_content(): void
    {
        $user = $this->makeBuyer();
        $service = app(SavedSearches::class);

        foreach ([
            fn () => $service->save($user, '', new SearchRequest(text: 'hose')),
            fn () => $service->save($user, 'x', new SearchRequest(text: 'hose'), 'hourly'),
            fn () => $service->save($user, 'Empty', new SearchRequest),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('should have been refused');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, SavedSearch::count());
    }
}
