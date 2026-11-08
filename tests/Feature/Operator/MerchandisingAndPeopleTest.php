<?php

namespace Tests\Feature\Operator;

use App\Models\Collection;
use App\Models\FeaturedStore;
use App\Models\OperatorAccessEntry;
use App\Models\OperatorAuditEntry;
use App\Models\Product;
use App\Models\SearchSynonym;
use App\Models\StoreCategory;
use App\Models\User;
use App\Services\Discovery\Collections;
use App\Services\Discovery\SearchLog;
use App\Services\Operator\FeaturedStores;
use App\Services\Search\SearchEngine;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchRequest;
use App\Services\Search\Synonyms;
use App\Services\Search\Vocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

class MerchandisingAndPeopleTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    private function audit(string $action): ?OperatorAuditEntry
    {
        return OperatorAuditEntry::query()->where('action', $action)->latest('id')->first();
    }

    private function search(string $q): int
    {
        Synonyms::forget();
        Vocabulary::forget();

        return app(SearchEngine::class)->search(SearchRequest::fromArray(['q' => $q]))->total;
    }

    // ---- collections ----------------------------------------------------------------------------------------------------

    public function test_a_manual_collection_is_created_scheduled_and_only_live_inside_its_dates(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $this->post(route('operator.collections.store'), ['title' => '', 'type' => 'manual'])->assertSessionHasErrors();
        $this->post(route('operator.collections.store'), ['title' => 'Back to school', 'type' => 'manual', 'visibility' => 'public', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->subDay()->format('Y-m-d\TH:i'), 'product_ids' => (string) $w['live']->id])
            ->assertSessionHasErrors();
        $this->assertSame(0, Collection::query()->count());

        $this->post(route('operator.collections.store'), ['title' => 'Back to school', 'type' => 'manual', 'visibility' => 'public', 'starts_at' => now()->addDay()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(10)->format('Y-m-d\TH:i'), 'product_ids' => $w['live']->id."\n".$w['pending']->id])
            ->assertRedirect(route('operator.collections.index'));

        $c = Collection::query()->first();
        $this->assertSame('Back to school', $c->title);
        $this->assertSame([$w['live']->id, $w['pending']->id], DB::table('collection_products')->where('collection_id', $c->id)->orderBy('position')->pluck('product_id')->all());
        $this->assertFalse($c->isLive(), 'scheduled for tomorrow');
        $this->assertSame(0, Collection::query()->live()->count());
        $this->assertNotNull($this->audit('collection.create'));
        $this->get(route('operator.collections.index'))->assertOk()->assertSee('Back to school')->assertSee('Scheduled');

        $this->travel(2)->days();
        $this->assertTrue($c->fresh()->isLive());
        $shown = app(Collections::class)->products($c->fresh());
        $this->assertSame([$w['live']->id], $shown->pluck('id')->all(), 'a listing that cannot be bought is never shown');

        $this->patch(route('operator.collections.update', $c), ['title' => 'Back to school 2027', 'type' => 'manual', 'visibility' => 'draft', 'product_ids' => (string) $w['live']->id])->assertRedirect();
        $this->assertSame('Back to school 2027', $c->fresh()->title);
        $this->assertSame('collection.update', $this->audit('collection.update')->action);

        $this->delete(route('operator.collections.destroy', $c))->assertRedirect(route('operator.collections.index'));
        $this->assertSame(0, Collection::query()->count());
        $this->assertNotNull($this->audit('collection.delete'));
        $this->assertTrue(Product::query()->whereKey($w['live']->id)->exists(), 'deleting a collection never deletes listings');
        $this->travelBack();
    }

    public function test_a_dynamic_collection_previews_what_its_rule_matches_and_matches_the_saved_collection(): void
    {
        $w = $this->operatorWorld();
        $kitchen = StoreCategory::query()->create(['name' => 'Kitchen', 'slug' => 'kitchen']);
        $cheap = $this->makeListing($w['seller'], ['name' => 'Cheap whisk', 'price' => 40, 'category_id' => $kitchen->id]);
        $dear = $this->makeListing($w['seller'], ['name' => 'Dear blender', 'price' => 900, 'category_id' => $kitchen->id]);
        app(SearchIndexer::class)->rebuild();
        $this->actingAs($w['operator']);

        $preview = $this->postJson(route('operator.collections.preview'), ['rule' => ['category' => $kitchen->id, 'price_max' => 100]])->assertOk()->json();

        $this->assertTrue($preview['ok']);
        $this->assertSame(1, $preview['total']);
        $this->assertSame('Cheap whisk', $preview['items'][0]['name']);

        $this->postJson(route('operator.collections.preview'), ['rule' => []])->assertStatus(422)->assertJsonPath('ok', false);
        $this->postJson(route('operator.collections.preview'), ['rule' => ['price_min' => 'abc', 'category' => $kitchen->id]])->assertStatus(422);

        $this->post(route('operator.collections.store'), ['title' => 'Under R100 kitchen', 'type' => 'dynamic', 'visibility' => 'public', 'rule' => ['category' => $kitchen->id, 'price_max' => 100]])->assertRedirect();
        $saved = Collection::query()->first();
        $this->assertSame('dynamic', $saved->type);
        $this->assertSame([$cheap->id], app(Collections::class)->products($saved)->pluck('id')->all(), 'the saved collection shows what the preview showed');
        $this->assertNotContains($dear->id, app(Collections::class)->products($saved)->pluck('id')->all());

        $this->get(route('operator.collections.create'))->assertOk()->assertSee('The rule')->assertSee('data-collection-preview', false);
        $this->get(route('operator.collections.edit', $saved))->assertOk()->assertSee('Under R100 kitchen');
    }

    public function test_the_collection_finder_returns_buyable_listings_only(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $this->getJson(route('operator.collections.find', ['q' => 'ware']))->assertOk()->assertJsonPath('items.0.name', 'Liveware toaster')->assertJsonCount(1, 'items');
        $this->getJson(route('operator.collections.find', ['q' => 'x']))->assertOk()->assertJsonCount(0, 'items');
        $this->actingAs($w['buyer'])->postJson(route('operator.collections.preview'), ['rule' => ['q' => 'x']])->assertForbidden();
    }

    // ---- synonyms and searches -------------------------------------------------------------------------------------------

    public function test_a_synonym_changes_what_search_finds_straight_away(): void
    {
        $w = $this->operatorWorld();
        $this->makeListing($w['seller'], ['name' => 'Garden gazebo', 'description' => 'A steel gazebo']);
        app(SearchIndexer::class)->rebuild();
        $this->actingAs($w['operator']);

        $this->assertSame(0, $this->search('marquee'));

        $this->post(route('operator.search.synonyms.store'), ['terms' => 'gazebo'])->assertSessionHasErrors();
        $this->post(route('operator.search.synonyms.store'), ['terms' => 'Gazebo, Marquee'])->assertRedirect(route('operator.search.synonyms'));

        $this->assertSame(2, SearchSynonym::query()->where('term', 'like', '%a%')->whereIn('term', ['gazebo', 'marquee'])->count());
        $this->assertGreaterThanOrEqual(1, $this->search('marquee'));
        $this->assertNotNull($this->audit('synonym.add'));

        $page = $this->get(route('operator.search.synonyms', ['test' => 'marquee']))->assertOk();
        $page->assertSee('marquee also finds gazebo')->assertSee('Garden gazebo');

        $syn = SearchSynonym::query()->where('term', 'marquee')->first();
        $this->delete(route('operator.search.synonyms.destroy', $syn))->assertRedirect();
        $this->assertSame(0, SearchSynonym::query()->whereIn('term', ['gazebo', 'marquee'])->count(), 'a group left with one word is dropped');
        $this->assertSame(0, $this->search('marquee'));
        $this->assertNotNull($this->audit('synonym.remove'));
    }

    public function test_a_word_can_join_an_existing_group_and_an_unknown_group_is_refused(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);
        $this->post(route('operator.search.synonyms.store'), ['terms' => 'gazebo, marquee']);

        $this->post(route('operator.search.synonyms.store'), ['terms' => 'party tent, canopy', 'join' => 'marquee'])->assertRedirect();
        $this->assertSame(1, SearchSynonym::query()->whereIn('term', ['gazebo', 'marquee', 'party tent', 'canopy'])->distinct()->count('group_key'));

        $this->post(route('operator.search.synonyms.store'), ['terms' => 'a, b', 'join' => 'nothing like this'])->assertSessionHasErrors();
    }

    public function test_zero_result_searches_are_reported_with_a_shortcut_to_make_a_synonym(): void
    {
        $w = $this->operatorWorld();
        foreach ([['jacuzzi', 0, 'a'], ['jacuzzi', 0, 'b'], ['jacuzzi', 0, 'a'], ['toaster', 3, 'c']] as [$q, $n, $who]) {
            DB::table('search_queries')->insert(['query' => $q, 'result_count' => $n, 'actor_hash' => $who, 'created_at' => now()]);
        }

        $page = $this->actingAs($w['operator'])->get(route('operator.search.queries'))->assertOk();
        $page->assertSee('jacuzzi')->assertSee('Found nothing')->assertSee('Create synonym');
        $page->assertSee(route('operator.search.synonyms', ['term' => 'jacuzzi']), false);
        $this->assertSame(['jacuzzi'], app(SearchLog::class)->zeroResults()->pluck('query')->all());
        $this->get(route('operator.search.synonyms', ['term' => 'jacuzzi']))->assertSee('jacuzzi');
    }

    public function test_the_search_report_says_no_data_yet_when_nobody_searched(): void
    {
        $this->actingAs($this->makeOperator())->get(route('operator.search.queries'))->assertOk()->assertSee('No data yet')->assertDontSee('0 searches');
    }

    public function test_complementary_rules_are_added_validated_and_removed_with_an_audit_entry(): void
    {
        $w = $this->operatorWorld();
        StoreCategory::query()->create(['name' => 'Laptops', 'slug' => 'laptops']);
        StoreCategory::query()->create(['name' => 'Laptop bags', 'slug' => 'laptop-bags']);
        $this->actingAs($w['operator']);

        $this->post(route('operator.search.complements.store'), ['category_slug' => 'laptops', 'complement_slug' => 'laptops'])->assertSessionHasErrors('complement_slug');
        $this->post(route('operator.search.complements.store'), ['category_slug' => 'laptops', 'complement_slug' => 'nonexistent'])->assertSessionHasErrors('complement_slug');
        $this->post(route('operator.search.complements.store'), ['category_slug' => 'laptops', 'complement_slug' => 'laptop-bags', 'note' => 'Protects the screen'])->assertRedirect();
        $this->post(route('operator.search.complements.store'), ['category_slug' => 'laptops', 'complement_slug' => 'laptop-bags'])->assertSessionHasErrors('complement_slug');

        $this->get(route('operator.search.complements'))->assertOk()->assertSee('Laptop bags')->assertSee('never as something measured');
        $id = DB::table('complementary_rules')->value('id');
        $this->delete(route('operator.search.complements.destroy', $id))->assertRedirect();
        $this->assertSame(0, DB::table('complementary_rules')->count());
        $this->assertNotNull($this->audit('complement.add'));
        $this->assertNotNull($this->audit('complement.remove'));
    }

    // ---- categories and featured stores -----------------------------------------------------------------------------------

    public function test_categories_can_be_created_renamed_moved_hidden_and_given_attributes(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $this->post(route('operator.catalogue.categories.store'), ['name' => 'Appliances'])->assertRedirect();
        $root = StoreCategory::query()->where('slug', 'appliances')->first();
        $this->post(route('operator.catalogue.categories.store'), ['name' => 'Kettles', 'parent_id' => $root->id]);
        $child = StoreCategory::query()->where('slug', 'kettles')->first();
        $this->assertSame($root->id, $child->parent_id);
        $this->assertSame(1, $child->depth);

        $this->patch(route('operator.catalogue.categories.update', $child), ['name' => 'Electric kettles', 'slug' => 'electric-kettles', 'sort_order' => 3, 'parent_id' => $root->id, 'is_active' => '1'])->assertRedirect();
        $this->assertSame('Electric kettles', $child->fresh()->name);
        $this->assertSame(3, $child->fresh()->sort_order);

        $this->patch(route('operator.catalogue.categories.update', $child), ['name' => 'Electric kettles', 'slug' => 'appliances', 'sort_order' => 3])->assertSessionHasErrors('slug');
        $this->patch(route('operator.catalogue.categories.update', $root), ['name' => 'Appliances', 'slug' => 'appliances', 'parent_id' => $child->id, 'is_active' => '1'])->assertSessionHasErrors('reason', null, 'category');
        $this->assertNull($root->fresh()->parent_id, 'a category cannot be moved under itself');

        $this->patch(route('operator.catalogue.categories.update', $child), ['name' => 'Electric kettles', 'slug' => 'electric-kettles', 'parent_id' => null])->assertRedirect();
        $this->assertNull($child->fresh()->parent_id);
        $this->assertFalse((bool) $child->fresh()->is_active, 'unticked means hidden');
        $this->assertSame(0, $child->fresh()->depth);

        $this->post(route('operator.catalogue.attributes.store', $child), ['key' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'unit' => 'L', 'is_facetable' => '1'])->assertRedirect();
        $this->post(route('operator.catalogue.attributes.store', $child), ['key' => 'capacity', 'label' => 'Again', 'type' => 'text'])->assertSessionHasErrors('key');
        $this->post(route('operator.catalogue.attributes.store', $child), ['key' => 'finish', 'label' => 'Finish', 'type' => 'enum', 'options' => 'Steel'])->assertSessionHasErrors('options');
        $this->post(route('operator.catalogue.attributes.store', $child), ['key' => 'finish', 'label' => 'Finish', 'type' => 'enum', 'options' => 'Steel, Plastic'])->assertRedirect();
        $this->assertSame(['Steel', 'Plastic'], $child->attributeDefinitions()->where('key', 'finish')->first()->options);

        $this->get(route('operator.catalogue.categories', ['category' => $child->id]))->assertOk()->assertSee('Capacity')->assertSee('Choice: Steel, Plastic')->assertSee('(hidden)');
        $attribute = $child->attributeDefinitions()->where('key', 'capacity')->first();
        $this->delete(route('operator.catalogue.attributes.destroy', [$child, $attribute]))->assertRedirect();
        $this->assertSame(1, $child->attributeDefinitions()->count());
        $this->assertNotNull($this->audit('category.create'));
        $this->assertNotNull($this->audit('category.update'));
        $this->assertNotNull($this->audit('category.attribute'));
    }

    public function test_featured_stores_are_chosen_limited_and_only_open_stores_are_shown(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);
        $store = $w['seller']->store;

        $this->post(route('operator.catalogue.featured.add'), ['store_id' => $store->id, 'note' => 'Great delivery record'])->assertRedirect();
        $this->assertSame([$store->id], app(FeaturedStores::class)->ids());
        $this->assertNotNull($this->audit('featured.add'));
        $this->get(route('operator.catalogue.featured'))->assertOk()->assertSee('Ridgeline Outfitters')->assertSee('Great delivery record');

        $store->forceFill(['is_active' => false])->save();
        $this->assertSame([], app(FeaturedStores::class)->ids(), 'a closed store drops out of the shop list');
        $this->assertSame(1, FeaturedStore::query()->count(), 'but stays on the operator list');

        $this->post(route('operator.catalogue.featured.add'), ['store_id' => $store->id])->assertSessionHasErrors('store_id', null);

        $store->forceFill(['is_active' => true])->save();
        foreach (range(1, FeaturedStores::LIMIT - 1) as $i) {
            app(FeaturedStores::class)->add($this->makeSeller()->store, $w['operator']);
        }
        $this->post(route('operator.catalogue.featured.add'), ['store_id' => $this->makeSeller()->store->id])->assertSessionHasErrors();

        $this->delete(route('operator.catalogue.featured.remove', $store))->assertRedirect();
        $this->assertFalse(FeaturedStore::query()->where('store_id', $store->id)->exists());
        $this->assertNotNull($this->audit('featured.remove'));
    }

    // ---- people, access log, operators -------------------------------------------------------------------------------------

    public function test_searching_masks_e_mail_addresses_and_writes_no_access_entry(): void
    {
        $w = $this->operatorWorld();
        $w['buyer']->forceFill(['email' => 'thandiwe.mokoena@example.test', 'name' => 'Thandiwe Mokoena'])->save();

        $page = $this->actingAs($w['operator'])->get(route('operator.people.index', ['type' => 'user', 'q' => 'Thandiwe']))->assertOk();

        $page->assertSee('Thandiwe Mokoena')->assertSee('t******@example.test')->assertDontSee('thandiwe.mokoena@example.test');
        $this->assertSame(0, OperatorAccessEntry::query()->count());
        $this->get(route('operator.people.index', ['type' => 'user', 'q' => 'thandiwe.mokoena@example.test']))->assertOk()->assertSee('t******@example.test');
        $this->get(route('operator.people.index', ['type' => 'order', 'q' => (string) $w['order']->id]))->assertOk()->assertSee('Order '.$w['order']->reference());
        $this->get(route('operator.people.index', ['type' => 'store', 'q' => 'Ridgeline']))->assertOk()->assertSee('Ridgeline Outfitters');
    }

    public function test_opening_a_person_a_store_or_an_order_writes_an_access_log_entry_with_the_purpose(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $this->get(route('operator.people.user', ['user' => $w['buyer'], 'purpose' => 'dispute']))->assertOk()->assertSee($w['buyer']->email)->assertSee($w['order']->reference());
        $entry = OperatorAccessEntry::query()->first();
        $this->assertSame($w['operator']->id, $entry->actor_id);
        $this->assertSame('user', $entry->subject_type);
        $this->assertSame($w['buyer']->id, $entry->subject_id);
        $this->assertSame('Looking into a dispute or a return', $entry->purpose);
        $this->assertNotNull($entry->created_at);

        $this->get(route('operator.people.store', ['store' => $w['seller']->store, 'purpose' => 'fraud']))->assertOk()->assertSee('Ridgeline Outfitters');
        $this->get(route('operator.people.order', $w['order']))->assertOk()->assertSee('Return #'.$w['return']->id);
        $this->assertSame(['user', 'store', 'order'], OperatorAccessEntry::query()->orderBy('id')->pluck('subject_type')->all());

        $this->get(route('operator.audit', ['tab' => 'access']))->assertOk()->assertSee($w['buyer']->name)->assertSee('Looking into a dispute or a return');
    }

    public function test_the_support_views_are_closed_to_everyone_but_operators_and_leave_no_entry(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['seller'])->get(route('operator.people.user', $w['buyer']))->assertForbidden();
        $this->actingAs($w['buyer'])->get(route('operator.people.order', $w['order']))->assertForbidden();
        $this->assertSame(0, OperatorAccessEntry::query()->count());
    }

    public function test_the_access_log_is_append_only(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator'])->get(route('operator.people.user', $w['buyer']));
        $entry = OperatorAccessEntry::query()->first();

        $this->expectException(\LogicException::class);
        $entry->delete();
    }

    public function test_granting_and_revoking_operator_access_needs_a_reason_and_keeps_one_operator(): void
    {
        $w = $this->operatorWorld();
        $second = $this->makeBuyer();
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($w['operator']);

        $this->post(route('operator.team.grant'), ['email' => 'nobody@example.test', 'reason' => 'Because'])->assertSessionHasErrors('email', null, 'grant');
        $this->post(route('operator.team.grant'), ['email' => $unverified->email, 'reason' => 'Joining the team'])->assertSessionHasErrors('email', null, 'grant');
        $this->post(route('operator.team.grant'), ['email' => $second->email, 'reason' => ''])->assertSessionHasErrors('reason', null, 'grant');
        $this->assertFalse((bool) $second->fresh()->is_platform_operator);

        $this->post(route('operator.team.grant'), ['email' => $second->email, 'reason' => 'Covers payments on weekdays'])->assertRedirect();
        $this->assertTrue((bool) $second->fresh()->is_platform_operator);
        $this->assertSame('Covers payments on weekdays', $this->audit('operator.grant')->reason);
        $this->get(route('operator.team'))->assertOk()->assertSee($second->name);

        $this->post(route('operator.team.revoke', $w['operator']), ['reason' => 'trying to remove myself'])->assertSessionHasErrors('reason', null, 'revoke-'.$w['operator']->id);
        $this->assertTrue((bool) $w['operator']->fresh()->is_platform_operator);

        $this->post(route('operator.team.revoke', $second), ['reason' => ''])->assertSessionHasErrors('reason', null, 'revoke-'.$second->id);
        $this->post(route('operator.team.revoke', $second), ['reason' => 'Left the team'])->assertRedirect();
        $this->assertFalse((bool) $second->fresh()->is_platform_operator);
        $this->assertSame('Left the team', $this->audit('operator.revoke')->reason);

        // The last operator can never be removed, even by another operator who is then the only one left standing.
        $third = $this->makeOperator();
        $this->actingAs($third)->post(route('operator.team.revoke', $w['operator']), ['reason' => 'Handing over'])->assertRedirect();
        $this->actingAs($w['seller'])->post(route('operator.team.revoke', $third), ['reason' => 'I want in'])->assertForbidden();
        $this->assertTrue((bool) $third->fresh()->is_platform_operator);
    }

    public function test_the_make_operator_command_promotes_and_revokes_by_e_mail(): void
    {
        $person = $this->makeBuyer();

        $this->artisan('emall:operator', ['email' => strtoupper($person->email)])->assertSuccessful();
        $this->assertTrue((bool) $person->fresh()->is_platform_operator);
        $this->artisan('emall:operator', ['email' => $person->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse((bool) $person->fresh()->is_platform_operator);
        $this->artisan('emall:operator', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
