<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * R-52 re-sync, tenant-OR sweep: a text filter that ORs several columns must stay grouped inside a closure so it cannot widen the owner scope
 * (the Mines bug: ->where(A)->orWhere(B) beside forTeam()). These pin the three list-page filters that combine text with a person's or a store's scope.
 */
class TenantSearchScopeTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    public function test_a_buyers_order_list_search_by_product_name_or_order_number_never_reaches_another_buyers_orders(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeBuyer();
        $me = $this->makeBuyer();
        $theirs = $this->placeOrder($other, [[$this->makeListing($seller, ['name' => 'Quokka Teapot']), 1]]);
        $mine = $this->placeOrder($me, [[$this->makeListing($seller, ['name' => 'Wombat Kettle']), 1]]);

        $byName = $this->actingAs($me)->get(route('orders.index', ['q' => 'Quokka']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Quokka Teapot', $byName);
        $this->assertStringNotContainsString($theirs->reference(), $byName);

        // Typing the other buyer's order number (the id branch is the OR) finds nothing either.
        $byNumber = $this->actingAs($me)->get(route('orders.index', ['q' => (string) $theirs->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString($theirs->reference(), $byNumber);
        $this->assertStringNotContainsString('Quokka Teapot', $byNumber);

        $own = $this->actingAs($me)->get(route('orders.index', ['q' => 'Wombat']))->assertOk()->getContent();
        $this->assertStringContainsString($mine->reference(), $own, 'the own order is found by what was bought');
        $this->assertStringContainsString($mine->reference(), $this->actingAs($me)->get(route('orders.index', ['q' => (string) $mine->id]))->getContent());
    }

    public function test_a_sellers_product_list_search_by_name_or_sku_never_reaches_another_stores_products(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeSeller();
        $this->makeListing($mine, ['name' => 'Fynbos Candle', 'sku' => 'FB-1']);
        $this->makeListing($theirs, ['name' => 'Fynbos Rival Candle', 'sku' => 'FB-2']);

        foreach (['Fynbos', 'FB-2', 'fb'] as $q) {
            $html = $this->actingAs($mine)->get(route('seller.products.index', ['q' => $q]))->assertOk()->getContent();
            $this->assertStringNotContainsString('Fynbos Rival Candle', $html, "q={$q}");
        }
        $this->assertStringContainsString('Fynbos Candle', $this->actingAs($mine)->get(route('seller.products.index', ['q' => 'Fynbos']))->getContent());
    }

    public function test_the_dashboard_entrance_animation_never_starts_from_opacity_zero(): void
    {
        // Chrome skips an opacity:0 paint for largest-contentful-paint and an opacity-only animation never repaints, so a from-0 rise
        // pushed the dashboards' LCP from first paint (1.2 s) to the page load (2.1 s) under the harness profile.
        $css = (string) file_get_contents(resource_path('css/dashboard.css'));
        $this->assertDoesNotMatchRegularExpression('/@keyframes dx-rise\s*\{\s*from\s*\{\s*opacity:\s*0\s*[;}]/', $css);
        $this->assertMatchesRegularExpression('/@keyframes dx-rise\s*\{\s*from\s*\{\s*opacity:\s*0\.01/', $css);
    }
}
