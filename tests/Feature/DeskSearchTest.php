<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\DeskSearch\DeskSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * R-52: the seller desk and the operator area have a real header search. It is scoped on the server from the signed-in person: a seller finds
 * only their own store's products, parcels and returns; an operator area search needs the operator flag; nothing a request carries can widen it.
 */
class DeskSearchTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    /** @return list<string> every row label in a JSON answer */
    private function labels(array $groups): array
    {
        return collect($groups)->flatMap(fn ($g) => collect($g['rows'])->pluck('label'))->all();
    }

    private function found(string $route, User $as, string $q, array $extra = []): array
    {
        return $this->actingAs($as)->getJson(route($route, ['q' => $q] + $extra))->assertOk()->json('groups');
    }

    // ── Seller: tenant isolation ───────────────────────────────────────────

    public function test_a_seller_finds_their_own_products_by_name_and_sku_and_never_another_stores(): void
    {
        $mine = $this->makeSeller(['name' => 'Karoo Home']);
        $theirs = $this->makeSeller(['name' => 'Other Shop']);
        $own = $this->makeListing($mine, ['name' => 'Zebrawood Salad Bowl', 'sku' => 'ZB-100']);
        $this->makeListing($theirs, ['name' => 'Zebrawood Rival Bowl', 'sku' => 'ZB-200']);

        $byName = $this->found('seller.find', $mine, 'zebrawood');
        $this->assertSame(['Zebrawood Salad Bowl'], array_column(collect($byName)->firstWhere('head', 'Your products')['rows'], 'label'));
        $this->assertNotContains('Zebrawood Rival Bowl', $this->labels($byName), 'another store is invisible');

        $bySku = $this->found('seller.find', $mine, 'ZB-2');
        $this->assertNotContains('Zebrawood Rival Bowl', $this->labels($bySku), "another store's SKU finds nothing");
        $this->assertSame(route('seller.products.edit', $own), collect($byName)->firstWhere('head', 'Your products')['rows'][0]['href'], 'a row opens the existing edit page');
    }

    public function test_a_seller_finds_only_their_own_parcels_by_order_number_and_by_buyer_name(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $buyer->forceFill(['name' => 'Nomvula Xyloquartz'])->save();
        $order = $this->placeOrder($buyer, [[$this->makeListing($mine, ['name' => 'Searchable Pan']), 1]]);
        $theirOrder = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($theirs, ['name' => 'Their Pan']), 1]]);

        $byRef = $this->found('seller.find', $mine, $order->reference());
        $this->assertContains('Order '.$order->reference(), $this->labels($byRef));

        $byName = $this->found('seller.find', $mine, 'Xyloquartz');
        $this->assertContains('Order '.$order->reference(), $this->labels($byName));

        // The other store's order number finds nothing for me, and mine finds nothing for them.
        $this->assertNotContains('Order '.$theirOrder->reference(), $this->labels($this->found('seller.find', $mine, $theirOrder->reference())));
        $this->assertNotContains('Order '.$order->reference(), $this->labels($this->found('seller.find', $theirs, $order->reference())));
    }

    public function test_the_seller_search_ignores_any_attempt_to_name_another_store_or_scope(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        $this->makeListing($theirs, ['name' => 'Smuggled Teapot']);

        foreach (['store_id' => $theirs->store->id, 'store' => $theirs->store->id, 'seller_id' => $theirs->id, 'scope' => 'all', 'context' => 'operator'] as $key => $value) {
            $this->assertNotContains('Smuggled Teapot', $this->labels($this->found('seller.find', $mine, 'Smuggled', [$key => $value])), "{$key} changes nothing");
        }
    }

    public function test_the_seller_search_needs_a_store_and_a_signed_in_person(): void
    {
        $this->getJson(route('seller.find', ['q' => 'abc']))->assertRedirect(route('login'));
        $this->actingAs($this->makeBuyer())->getJson(route('seller.find', ['q' => 'abc']))->assertForbidden();
        // An operator without a store gets no seller search either.
        $this->actingAs($this->makeOperator())->getJson(route('seller.find', ['q' => 'abc']))->assertForbidden();
    }

    public function test_a_seller_without_the_operator_flag_cannot_use_the_operator_search_even_with_a_store(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['name' => 'Operator Only Probe']);

        $this->actingAs($seller)->getJson(route('operator.find', ['q' => 'Operator']))->assertForbidden();
        $this->assertSame([], app(DeskSearch::class)->forOperator($seller, 'Operator'), 'the service refuses as well (defence in depth)');
    }

    public function test_the_seller_search_states_short_empty_and_no_match(): void
    {
        $mine = $this->makeSeller();
        $this->makeListing($mine, ['name' => 'Walnut Spoon']);

        $this->assertSame([], $this->found('seller.find', $mine, 'w'), 'a single character searches nothing');
        $this->assertSame([], $this->found('seller.find', $mine, ''), 'an empty box searches nothing');
        $none = $this->found('seller.find', $mine, 'qqqqzzzz');
        $this->assertSame(['Not here?'], array_column($none, 'head'), 'no match leaves only the way to the full list');
        $this->assertSame(route('seller.products.index', ['q' => 'qqqqzzzz']), $none[0]['rows'][0]['href']);
    }

    public function test_search_wildcards_are_literal_and_an_injection_string_is_just_text(): void
    {
        $mine = $this->makeSeller();
        $this->makeListing($mine, ['name' => 'Plain Kettle']);
        $this->makeListing($mine, ['name' => '100% Cotton Towel']);

        $this->assertNotContains('Plain Kettle', $this->labels($this->found('seller.find', $mine, '%%')), 'a % is not a wildcard');
        $this->assertContains('100% Cotton Towel', $this->labels($this->found('seller.find', $mine, '100%')));
        $this->assertNotContains('Plain Kettle', $this->labels($this->found('seller.find', $mine, "x' OR '1'='1")));
        $this->assertSame(['Not here?'], array_column($this->found('seller.find', $mine, str_repeat('a', 500)), 'head'), 'an overlong query is cut and finds nothing');
    }

    public function test_each_group_is_capped_and_answers_are_private_and_not_cached(): void
    {
        $mine = $this->makeSeller();
        foreach (range(1, 12) as $n) {
            $this->makeListing($mine, ['name' => "Capped Mug {$n}"]);
        }

        $response = $this->actingAs($mine)->getJson(route('seller.find', ['q' => 'Capped Mug']))->assertOk();
        $rows = collect($response->json('groups'))->firstWhere('head', 'Your products')['rows'];
        $this->assertCount(DeskSearch::LIMIT, $rows);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    // ── Operator ───────────────────────────────────────────────────────────

    public function test_an_operator_finds_listings_stores_orders_and_people_with_masked_email(): void
    {
        $operator = $this->makeOperator();
        $seller = $this->makeSeller(['name' => 'Ridgeline Quasar Outfitters']);
        $listing = $this->makeListing($seller, ['name' => 'Quasar Kettle', 'status' => 'pending_approval']);
        $person = User::factory()->withPersonalTeam()->create(['name' => 'Quasar Customer', 'email' => 'quasar.customer@example.test']);
        $order = $this->placeOrder($person, [[$this->makeListing($seller, ['name' => 'Another Pan']), 1]]);

        $groups = $this->found('operator.find', $operator, 'Quasar');
        $this->assertSame(['Listings', 'Stores', 'People', 'Not here?'], array_column($groups, 'head'));
        $this->assertSame(route('operator.products.show', $listing), collect($groups)->firstWhere('head', 'Listings')['rows'][0]['href']);
        $this->assertSame(route('operator.stores.show', $seller->store), collect($groups)->firstWhere('head', 'Stores')['rows'][0]['href']);
        $this->assertStringNotContainsString('quasar.customer@example.test', json_encode($groups), 'an e-mail address is never printed in full here');

        $byOrder = $this->found('operator.find', $operator, $order->reference());
        $this->assertContains('Order '.$order->reference(), $this->labels($byOrder));
        $this->assertSame(route('operator.people.order', $order), collect($byOrder)->firstWhere('head', 'Orders')['rows'][0]['href'], 'opening an order is still the access-logged page');
    }

    public function test_the_operator_search_is_refused_to_everyone_who_is_not_an_operator(): void
    {
        $this->getJson(route('operator.find', ['q' => 'abc']))->assertRedirect(route('login'));
        $this->actingAs($this->makeBuyer())->getJson(route('operator.find', ['q' => 'abc']))->assertForbidden();
    }

    // ── The header carries it ──────────────────────────────────────────────

    public function test_the_seller_desk_and_the_operator_area_print_a_search_on_every_page_with_their_own_endpoint(): void
    {
        $seller = $this->makeSeller();
        $operator = $this->makeOperator();

        foreach ([route('seller.dashboard'), route('seller.products.index'), route('seller.orders.index'), route('seller.returns')] as $url) {
            $html = $this->actingAs($seller)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-desk-search="'.route('seller.find').'"', $html, $url);
            $this->assertStringNotContainsString(route('operator.find'), $html, 'a seller page never names the operator search');
            $this->assertSame(1, preg_match_all('/class="dsr-input"/', $html), 'one pill in the desk header');
        }

        foreach ([route('operator.home'), route('operator.products.index'), route('operator.stores.index')] as $url) {
            $html = $this->actingAs($operator)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('data-desk-search="'.route('operator.find').'"', $html, $url);
            $this->assertStringContainsString('name="status" value="all"', $html, 'the no-JavaScript submit lists every status');
        }
    }

    public function test_without_javascript_the_desk_pill_submits_to_the_existing_filtered_list(): void
    {
        $seller = $this->makeSeller();
        $html = $this->actingAs($seller)->get(route('seller.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form role="search" method="get"\s+action="'.preg_quote(route('seller.products.index'), '#').'"#', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="search"[^>]*name="q"/', $html);
    }

    public function test_a_seller_orders_page_search_does_not_leak_through_the_order_model(): void
    {
        // The parcels group joins orders to buyers: a buyer's name must only match parcels of this store.
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        $buyer = $this->makeBuyer();
        $buyer->forceFill(['name' => 'Priya Unmatchedname'])->save();
        $this->placeOrder($buyer, [[$this->makeListing($theirs, ['name' => 'Elsewhere Item']), 1]]);

        $this->assertSame([], array_values(array_filter(array_column($this->found('seller.find', $mine, 'Unmatchedname'), 'head'), fn ($h) => $h === 'Your orders')));
        $this->assertInstanceOf(Order::class, Order::query()->first());
    }
}
