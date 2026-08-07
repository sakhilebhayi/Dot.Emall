<?php

namespace Tests\Feature\Marketplace;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Before the platform-loop pass there was no route where a buyer could
 * look up an order by id at all. These tests exist specifically to lock
 * in that a customer can never view another customer's order once that
 * route exists — see app/Policies/OrderPolicy.php.
 */
class OrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'subtotal' => 50,
            'total' => 50,
        ], $overrides));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/orders')->assertRedirect('/login');
    }

    public function test_a_user_can_view_their_own_order(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $order = $this->makeOrder($user);

        $this->actingAs($user)
            ->get(route('orders.show', $order))
            ->assertOk()
            ->assertViewIs('orders.show');
    }

    public function test_a_user_cannot_view_another_users_order(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $intruder = User::factory()->withPersonalTeam()->create();
        $order = $this->makeOrder($owner);

        $this->actingAs($intruder)
            ->get(route('orders.show', $order))
            ->assertForbidden();
    }

    public function test_the_orders_index_only_lists_the_current_users_own_orders(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $otherUser = User::factory()->withPersonalTeam()->create();

        $this->makeOrder($user, ['subtotal' => 11, 'total' => 11]);
        $this->makeOrder($otherUser, ['subtotal' => 999, 'total' => 999]);

        $response = $this->actingAs($user)->get(route('orders.index'));

        $response->assertOk();
        $response->assertSee('$11.00');
        $response->assertDontSee('$999.00');
    }
}
