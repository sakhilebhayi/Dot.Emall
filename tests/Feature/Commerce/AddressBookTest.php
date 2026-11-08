<?php

namespace Tests\Feature\Commerce;

use App\Models\Address;
use App\Models\User;
use App\Services\Addresses\AddressBook;
use App\Services\CheckoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsMarketplace;
use Tests\TestCase;

class AddressBookTest extends TestCase
{
    use BuildsMarketplace;
    use RefreshDatabase;

    private function fields(array $override = []): array
    {
        return array_merge([
            'recipient_name' => 'Thandiwe Mokoena', 'phone' => '+27 82 555 0142', 'line1' => '14 Protea Lane', 'suburb' => 'Parkhurst',
            'city' => 'Johannesburg', 'province' => 'Gauteng', 'postal_code' => '2193', 'country' => 'ZA',
        ], $override);
    }

    public function test_the_first_address_becomes_the_default_shipping_address(): void
    {
        $user = $this->makeBuyer();
        $book = app(AddressBook::class);

        $first = $book->add($user, $this->fields());
        $second = $book->add($user, $this->fields(['line1' => '2 Long Street', 'label' => 'Work']));

        $this->assertTrue($first->is_default_shipping);
        $this->assertFalse($second->is_default_shipping);
    }

    public function test_setting_a_default_clears_the_previous_one(): void
    {
        $user = $this->makeBuyer();
        $book = app(AddressBook::class);
        $a = $book->add($user, $this->fields());
        $b = $book->add($user, $this->fields(['line1' => '2 Long Street']));

        $book->setDefault($b);
        $book->setDefault($a, 'billing');

        $this->assertSame([false, true], [$a->fresh()->is_default_shipping, $b->fresh()->is_default_shipping]);
        $this->assertSame([true, false], [$a->fresh()->is_default_billing, $b->fresh()->is_default_billing]);
        $this->assertSame($b->id, $book->defaultShipping($user)->id);
    }

    public function test_deleting_the_default_hands_the_default_to_another_address(): void
    {
        $user = $this->makeBuyer();
        $book = app(AddressBook::class);
        $a = $book->add($user, $this->fields());
        $b = $book->add($user, $this->fields(['line1' => '2 Long Street']));

        $book->delete($a);

        $this->assertTrue($b->fresh()->is_default_shipping);
    }

    public function test_south_african_addresses_need_a_real_province_and_a_four_digit_postal_code(): void
    {
        $book = app(AddressBook::class);
        $user = $this->makeBuyer();

        foreach ([['province' => 'Narnia'], ['province' => null], ['postal_code' => '219'], ['postal_code' => '21934'], ['postal_code' => 'ABCD'], ['phone' => 'call me'], ['line1' => ''], ['city' => '']] as $bad) {
            try {
                $book->add($user, $this->fields($bad));
                $this->fail('expected a refusal for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }

        $this->assertSame(0, Address::query()->count());
    }

    public function test_other_countries_get_generic_rules(): void
    {
        $address = app(AddressBook::class)->add($this->makeBuyer(), $this->fields([
            'country' => 'gb', 'province' => null, 'postal_code' => 'SW1A 1AA', 'city' => 'London',
        ]));

        $this->assertSame('GB', $address->country);
        $this->assertSame('SW1A 1AA', $address->postal_code);
    }

    public function test_an_address_book_is_limited(): void
    {
        $user = $this->makeBuyer();
        Address::factory()->count(AddressBook::MAX_ADDRESSES)->create(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);
        app(AddressBook::class)->add($user, $this->fields());
    }

    public function test_only_the_owner_may_read_or_change_an_address(): void
    {
        $owner = $this->makeBuyer();
        $other = $this->makeBuyer();
        $address = Address::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue(Gate::forUser($owner)->allows('update', $address));
        $this->assertFalse(Gate::forUser($other)->allows('view', $address));
        $this->assertFalse(Gate::forUser($other)->allows('delete', $address));
    }

    public function test_an_order_keeps_an_immutable_snapshot_of_the_address_it_was_sent_to(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller, ['price' => 20]);
        $buyer = $this->makeBuyer();
        $address = app(AddressBook::class)->add($buyer, $this->fields(['instructions' => 'Gate code 4411']));

        $order = $this->placeOrder($buyer, [[$product, 1]], ['address_id' => $address->id, 'shipping_address' => null]);

        $address->update(['line1' => '99 Somewhere Else', 'city' => 'Pretoria']);
        $address->delete();
        $order = $order->fresh();

        $this->assertSame('14 Protea Lane', $order->shipping_snapshot['line1']);
        $this->assertSame('Gate code 4411', $order->shipping_snapshot['instructions']);
        $this->assertSame('Gauteng', $order->shipping_snapshot['province']);
        $this->assertStringContainsString('14 Protea Lane, Parkhurst, Johannesburg, Gauteng, 2193', $order->shipping_address, 'the legacy one-line string is still written');
        $this->assertSame('Thandiwe Mokoena', $order->shipping_name);
    }

    public function test_a_checkout_cannot_use_someone_elses_saved_address(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeListing($seller);
        $victim = $this->makeBuyer();
        $theirs = Address::factory()->create(['user_id' => $victim->id]);

        $this->expectException(CheckoutException::class);
        $this->placeOrder($this->makeBuyer(), [[$product, 1]], ['address_id' => $theirs->id, 'shipping_address' => null]);
    }

    public function test_orders_placed_with_the_old_one_line_address_still_work_and_have_no_snapshot(): void
    {
        $order = $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]]);

        $this->assertNull($order->fresh()->shipping_snapshot);
        $this->assertStringContainsString('Protea Lane', $order->shipping_address);
    }

    public function test_free_form_address_fields_at_checkout_are_validated_and_snapshotted(): void
    {
        $product = $this->makeListing($this->makeSeller());
        $order = $this->placeOrder($this->makeBuyer(), [[$product, 1]], ['shipping_address' => null, 'address' => $this->fields()]);

        $this->assertSame('Parkhurst', $order->fresh()->shipping_snapshot['suburb']);

        $this->expectException(CheckoutException::class);
        $this->placeOrder($this->makeBuyer(), [[$this->makeListing($this->makeSeller()), 1]], ['shipping_address' => null, 'address' => $this->fields(['postal_code' => '12'])]);
    }

    public function test_user_relation_lists_addresses(): void
    {
        $user = User::factory()->create();
        Address::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->addresses);
    }
}
