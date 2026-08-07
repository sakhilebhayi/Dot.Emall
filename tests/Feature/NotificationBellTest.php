<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = Store::create([
            'owner_id' => $seller->id,
            'name' => 'Notify Store',
            'slug' => 'notify-store',
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'subtotal' => 20,
            'total' => 20,
        ]);

        $seller->notify(new NewOrderNotification($order, $store));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($seller)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('New order received');

        $this->assertEquals(1, $seller->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $seller = User::factory()->withPersonalTeam()->create();
        $store = Store::create([
            'owner_id' => $seller->id,
            'name' => 'Notify Store Two',
            'slug' => 'notify-store-two',
            'is_active' => true,
        ]);
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'subtotal' => 20,
            'total' => 20,
        ]);

        $seller->notify(new NewOrderNotification($order, $store));

        Livewire::actingAs($seller)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $seller->fresh()->unreadNotifications()->count());
    }
}
