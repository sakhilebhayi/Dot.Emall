<?php

namespace Tests\Feature\Marketplace;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModerationTest extends TestCase
{
    use RefreshDatabase;

    private function pendingProduct(): Product
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Store',
            'slug' => 'test-store-'.uniqid(),
            'is_active' => true,
        ]);

        return Product::create([
            'store_id' => $store->id,
            'name' => 'Widget',
            'slug' => 'widget-'.uniqid(),
            'price' => 19.99,
            'stock' => 10,
            'is_active' => true,
            'status' => 'pending_approval',
        ]);
    }

    private function operator(): User
    {
        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    public function test_operator_can_view_pending_products(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->get(route('operator.products.index'))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_operator_can_approve_a_pending_product(): void
    {
        $product = $this->pendingProduct();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.products.approve', $product))
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($operator->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
    }

    public function test_approving_makes_the_product_immediately_browsable(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->post(route('operator.products.approve', $product));

        $this->assertTrue(Product::browsable()->whereKey($product->id)->exists());
    }

    public function test_reject_without_a_reason_is_blocked(): void
    {
        $product = $this->pendingProduct();

        $this->actingAs($this->operator())
            ->post(route('operator.products.reject', $product), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame('pending_approval', $product->fresh()->status);
    }

    public function test_reject_with_a_reason_marks_it_rejected(): void
    {
        $product = $this->pendingProduct();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('operator.products.reject', $product), ['reason' => 'Prohibited item.'])
            ->assertRedirect();

        $fresh = $product->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Prohibited item.', $fresh->rejected_reason);
        $this->assertSame($operator->id, $fresh->reviewed_by);
    }

    public function test_non_operator_cannot_view_or_act_on_the_review_screen(): void
    {
        $product = $this->pendingProduct();
        $nonOperator = User::factory()->create();

        $this->actingAs($nonOperator)
            ->get(route('operator.products.index'))
            ->assertForbidden();

        $this->actingAs($nonOperator)
            ->post(route('operator.products.approve', $product))
            ->assertForbidden();

        $this->assertSame('pending_approval', $product->fresh()->status);
    }

    public function test_approving_an_already_decided_product_is_refused(): void
    {
        $product = $this->pendingProduct();
        $product->update(['status' => 'approved']);

        $this->actingAs($this->operator())
            ->post(route('operator.products.approve', $product))
            ->assertSessionHasErrors();

        $this->assertSame('approved', $product->fresh()->status);
    }
}
