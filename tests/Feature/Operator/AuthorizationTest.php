<?php

namespace Tests\Feature\Operator;

use App\Models\Collection;
use App\Models\OperatorAuditEntry;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SearchSynonym;
use App\Models\StoreCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsOperatorWorld;
use Tests\TestCase;

/**
 * Every operator route, for a guest, a buyer, a seller and an operator. Nobody but an operator gets past the door, and nothing
 * changes when they are refused. The routes are read from the router, so a route added later without the middleware fails here.
 */
class AuthorizationTest extends TestCase
{
    use BuildsOperatorWorld;
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> method and URL for every operator route, parameters filled with real ids */
    private function routes(array $w): array
    {
        $category = StoreCategory::query()->create(['name' => 'Kettles', 'slug' => 'kettles']);
        $collection = Collection::factory()->create();
        $synonym = SearchSynonym::query()->create(['group_key' => 'g', 'term' => 'kettle']);
        $attribute = $category->attributeDefinitions()->create(['key' => 'power', 'label' => 'Power', 'type' => 'text']);
        $payment = Payment::query()->first();
        $refund = Refund::query()->create(['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'amount_cents' => 1000, 'currency' => 'ZAR', 'status' => 'refund_pending', 'kind' => 'cancellation']);
        $store = $w['seller']->store;

        $fill = [
            '{product}' => $w['pending']->id, '{store}' => $store->id, '{review}' => $w['held']->id, '{question}' => $w['question']->id,
            '{answer}' => 1, '{type}' => 'review', '{id}' => $w['reported']->id, '{returnRequest}' => $w['return']->id,
            '{collection}' => $collection->id, '{synonym}' => $synonym->id, '{rule}' => 1, '{category}' => $category->id,
            '{attribute}' => $attribute->id, '{user}' => $w['buyer']->id, '{order}' => $w['order']->id,
            '{payment}' => $payment?->id ?? 1, '{refund}' => $refund?->id ?? 1,
        ];

        $out = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'operator.')) {
                continue;
            }

            $uri = '/'.strtr($route->uri(), $fill);
            $this->assertStringNotContainsString('{', $uri, 'unfilled parameter in '.$route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, $uri, $route->getName()];
            }
        }

        return $out;
    }

    public function test_every_operator_route_is_behind_the_operator_middleware(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'operator.')) {
                $this->assertContains('operator', $route->gatherMiddleware(), $route->getName().' has no operator middleware');
                $this->assertNotContains('Closure', array_map(fn ($a) => is_object($a) ? get_class($a) : 'x', [$route->getAction('uses')]), $route->getName().' is a closure: route:cache cannot store it');
            }
        }
    }

    public function test_guests_are_sent_to_sign_in_and_buyers_and_sellers_are_refused_everywhere(): void
    {
        $w = $this->operatorWorld();
        $routes = $this->routes($w);
        $this->assertGreaterThan(50, count($routes));

        foreach ($routes as [$method, $uri, $name]) {
            $guest = $this->call($method, $uri);
            $this->assertTrue(in_array($guest->getStatusCode(), [302, 401], true) && ($guest->getStatusCode() === 401 || str_contains((string) $guest->headers->get('Location'), 'login')), "guest on {$method} {$name}: ".$guest->getStatusCode());
        }

        foreach ($routes as [$method, $uri, $name]) {
            foreach (['buyer', 'seller'] as $who) {
                $response = $this->actingAs($w[$who])->call($method, $uri);
                $this->assertSame(403, $response->getStatusCode(), "{$who} on {$method} {$name} got ".$response->getStatusCode());
            }
        }
    }

    public function test_a_refused_request_changes_nothing(): void
    {
        $w = $this->operatorWorld();

        $this->actingAs($w['buyer'])->post(route('operator.products.approve', $w['pending']))->assertForbidden();
        $this->actingAs($w['seller'])->post(route('operator.products.reject', $w['pending']), ['reason' => 'Because I say so'])->assertForbidden();
        $this->actingAs($w['seller'])->post(route('operator.returns.settle', $w['return']), ['decision' => 'uphold_decline', 'note' => 'seller decides'])->assertForbidden();

        $this->assertSame('pending_approval', $w['pending']->fresh()->status);
        $this->assertSame('escalated', $w['return']->fresh()->status->value);
        $this->assertSame(0, OperatorAuditEntry::query()->count());
    }

    public function test_an_operator_opens_every_page_without_error(): void
    {
        $w = $this->operatorWorld();
        $this->actingAs($w['operator']);

        $opened = 0;

        foreach ($this->routes($w) as [$method, $uri, $name]) {
            if ($method !== 'GET' || in_array($name, ['operator.metrics.export', 'operator.collections.find'], true)) {
                continue;
            }

            $this->get($uri)->assertOk();
            $opened++;
        }

        $this->assertGreaterThan(25, $opened);
        $this->get(route('operator.metrics.export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
