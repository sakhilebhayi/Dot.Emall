<?php

namespace Tests\Feature\Shop;

use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Guests\GuestOrderAccess;
use App\Services\Returns\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCommerce;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class ReturnFlowTest extends TestCase
{
    use BuildsCommerce;
    use BuildsMarketplace;
    use BuildsPayments;
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seller = $this->makeSeller(['name' => 'Returns Stores']);
        $this->withDelivery($this->seller, ['price' => 40]);
        $this->buyer = $this->makeBuyer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function delivered(?User $buyer = null): Order
    {
        $this->enablePaystack();
        $order = $this->placeOrder($buyer ?? $this->buyer, [[$this->makeListing($this->seller, ['name' => 'Returnable Kettle', 'price' => 100, 'stock' => 5]), 1]]);
        $this->payOrder($order, (string) random_int(1000000, 9999999999));

        return $this->deliver($order->fresh());
    }

    private function form(array $over = []): array
    {
        return array_merge(['reason_category' => 'faulty', 'note' => 'The lid does not fit and the handle is cracked.'], $over);
    }

    private function jpg(string $name = 'fault.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 480);
    }

    public function test_a_buyer_asks_for_a_return_with_a_photo_and_follows_it_to_a_refund(): void
    {
        $order = $this->delivered();
        $line = $order->items()->first();

        $this->actingAs($this->buyer)->get(route('orders.returns.create', [$order, $line]))->assertOk()
            ->assertSee('Why are you returning it?')->assertSee('The seller pays for the return.')->assertSee('You pay for the return.')->assertSee('Photos (optional)')->assertSee('7 days of delivery');

        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $line]), $this->form() + ['photos' => [$this->jpg()]])->assertSessionHasNoErrors();

        $return = ReturnRequest::firstOrFail();
        $this->assertSame(ReturnStatus::Requested, $return->status);
        $this->assertCount(1, $return->photo_paths);
        $this->assertStringStartsWith('returns/'.$order->id.'/', $return->photo_paths[0]);
        Storage::disk('local')->assertExists($return->photo_paths[0]);

        $this->actingAs($this->buyer)->get(route('account.returns.show', $return))->assertOk()->assertSee('Waiting for the seller')->assertSee('days from your request')->assertSee('The lid does not fit')->assertSee('Your photo 1');

        // The seller approves; the goods come back; the refund is recorded and the buyer can read each step.
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'queued', 'data' => ['id' => 8001, 'status' => 'pending']], 200)]);
        app(ReturnService::class)->approve($return, $this->seller, 'Please post it back with the box.');
        $this->actingAs($this->buyer)->get(route('account.returns.show', $return))->assertSee('Approved: send the item back')->assertSee('Please post it back with the box.');

        app(ReturnService::class)->markReceived($return->fresh(), $this->seller);
        $this->actingAs($this->buyer)->get(route('account.returns.show', $return))->assertSee('Refunded')->assertSee('Delivery fees are not refunded by a return')->assertSee('R100.00');
        $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertSee('For a returned item.');
    }

    public function test_a_decline_shows_its_stated_reason_and_the_buyer_can_send_it_to_the_emall_team(): void
    {
        $order = $this->delivered();
        $line = $order->items()->first();
        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $line]), $this->form(['reason_category' => 'changed_mind', 'note' => null]));
        $return = ReturnRequest::firstOrFail();

        app(ReturnService::class)->decline($return, $this->seller, 'It was used and the seal is broken.');

        $this->actingAs($this->buyer)->get(route('account.returns.show', $return))->assertSee('The seller declined this return')->assertSee('It was used and the seal is broken.')->assertSee('Send to the Emall team');
        $this->actingAs($this->buyer)->get(route('orders.show', $order))->assertSee('the seller said: It was used and the seal is broken.');

        $this->actingAs($this->buyer)->post(route('account.returns.escalate', $return), ['note' => 'It was sealed when I opened it.'])->assertRedirect(route('account.returns.show', $return));
        $this->assertSame(ReturnStatus::Escalated, $return->fresh()->status);
        $this->actingAs($this->buyer)->get(route('account.returns.show', $return))->assertSee('With the Emall team')->assertSee('You sent this to us after the seller declined');
        $this->actingAs($this->buyer)->post(route('account.returns.escalate', $return))->assertSessionHasErrors('status');
    }

    public function test_an_unanswered_request_is_explained_as_escalating_after_ten_days(): void
    {
        $order = $this->delivered();
        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $order->items()->first()]), $this->form());

        Carbon::setTestNow(now()->addDays(11));
        $this->assertSame(1, app(ReturnService::class)->escalateOverdue());

        $this->actingAs($this->buyer)->get(route('account.returns.show', ReturnRequest::first()))->assertSee('With the Emall team')->assertSee('The seller did not answer in time, so this came to us automatically.');
    }

    public function test_photos_are_checked_by_content_size_and_count_and_a_failed_request_leaves_no_files(): void
    {
        $order = $this->delivered();
        $line = $order->items()->first();
        $url = route('orders.returns.store', [$order, $line]);

        $disguised = UploadedFile::fake()->createWithContent('not-a-photo.jpg', '<?php echo "hello"; ?> this is plain text pretending to be an image');
        $this->actingAs($this->buyer)->post($url, $this->form() + ['photos' => [$disguised]])->assertSessionHasErrors('photos');

        $big = UploadedFile::fake()->create('huge.jpg', 5000, 'image/jpeg');
        $this->actingAs($this->buyer)->post($url, $this->form() + ['photos' => [$big]])->assertSessionHasErrors('photos');

        $five = array_map(fn ($i) => $this->jpg("p{$i}.jpg"), range(1, 5));
        $this->actingAs($this->buyer)->post($url, $this->form() + ['photos' => $five])->assertSessionHasErrors('photos');

        $this->actingAs($this->buyer)->post($url, $this->form(['reason_category' => 'other', 'note' => '']) + ['photos' => [$this->jpg()]])->assertSessionHasErrors('note');

        $this->assertSame(0, ReturnRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'a refused request leaves no stored photo behind');
    }

    public function test_photos_are_streamed_only_to_the_buyer_the_seller_and_the_operator(): void
    {
        $order = $this->delivered();
        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $order->items()->first()]), $this->form() + ['photos' => [$this->jpg()]]);
        $return = ReturnRequest::firstOrFail();
        $url = route('return-photos.show', [$return, 0]);
        $operator = User::factory()->create(['is_platform_operator' => true]);

        foreach ([$this->buyer, $this->seller, $operator] as $person) {
            $this->actingAs($person)->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        }

        $this->actingAs($this->makeBuyer())->get($url)->assertNotFound();
        $this->actingAs($this->makeSeller())->get($url)->assertNotFound();
        auth()->forgetGuards();
        $this->flushSession();
        $this->get($url)->assertNotFound();
        $this->actingAs($this->buyer)->get(route('return-photos.show', [$return, 7]))->assertNotFound();
    }

    public function test_the_window_closing_and_a_second_request_are_refused_in_words(): void
    {
        $order = $this->delivered();
        $line = $order->items()->first();
        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $line]), $this->form());

        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$order, $line]), $this->form())->assertSessionHasErrors('order_item');
        $this->actingAs($this->buyer)->get(route('orders.returns.create', [$order, $line]))->assertRedirect(route('orders.show', $order))->assertSessionHas('error');

        $late = $this->delivered();
        Carbon::setTestNow(now()->addDays(8));
        $this->actingAs($this->buyer)->get(route('orders.returns.create', [$late, $late->items()->first()]))->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('7-day return window has closed', session('error'));
    }

    public function test_the_returns_list_shows_my_returns_and_what_i_can_still_return_and_nobody_elses(): void
    {
        $mine = $this->delivered();
        $other = $this->delivered($this->makeBuyer());
        $this->actingAs($this->buyer)->post(route('orders.returns.store', [$mine, $mine->items()->first()]), $this->form());
        $third = $this->delivered();

        $page = $this->actingAs($this->buyer)->get(route('account.returns'))->assertOk();
        $page->assertSee('Your return requests')->assertSee($mine->reference())->assertSee('Requested')->assertSee('You can still return')->assertSee('Start a return');
        $this->assertStringContainsString(route('orders.returns.create', [$third, $third->items()->first()]), $page->getContent());
        $this->assertStringNotContainsString(route('orders.returns.create', [$other, $other->items()->first()]), $page->getContent());

        $stranger = $this->makeBuyer();
        $this->actingAs($stranger)->get(route('account.returns.show', ReturnRequest::first()))->assertForbidden();
    }

    public function test_a_guest_asks_for_a_return_from_their_own_page_and_sees_its_state(): void
    {
        $this->enablePaystack();
        $variant = $this->makeListing($this->seller, ['name' => 'Guest Returnable Lamp', 'price' => 100, 'stock' => 5])->defaultVariant()->first();
        $order = app(CheckoutService::class)->placeAsGuest('guest.return@example.test', [['variant_id' => $variant->id, 'quantity' => 1]], ['shipping_address' => '1 Test Street, Cape Town', 'shipping_name' => 'Guest'], (string) Str::uuid());
        $order = $this->payOrder($order, (string) random_int(1000000, 9999999999))->order->fresh();
        $order = $this->deliver($order);
        $line = $order->items()->first();

        $this->get(route('guest.orders.returns.create', [$order, $line]))->assertNotFound();
        $this->get(route('guest.orders.access', ['order' => $order->id, 'token' => app(GuestOrderAccess::class)->tokenFor($order)]))->assertRedirect();

        $this->get(route('guest.orders.returns.create', [$order, $line]))->assertOk();
        $this->post(route('guest.orders.returns.store', [$order, $line]), $this->form() + ['photos' => [$this->jpg()]])->assertRedirect(route('guest.orders.show', $order))->assertSessionHas('status');

        $return = ReturnRequest::firstOrFail();
        $this->assertNull($return->requester_id);
        $this->get(route('guest.orders.show', $order))->assertSee('Return requested');
        $this->get(route('return-photos.show', [$return, 0]))->assertOk();
    }
}
