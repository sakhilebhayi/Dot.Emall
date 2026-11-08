<?php

namespace App\Services\Checkout;

use App\Models\Address;
use App\Services\Addresses\AddressRules;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Everything the one-page checkout draws. The page works without script: its sections are drawn from what the SERVER knows (the
 * session draft, the cart, the address book) and which step is current follows from that, so a reload, a back button or a
 * validation error never loses what was typed.
 */
class CheckoutPage
{
    public const STEPS = ['Contact', 'Delivery', 'Payment', 'Review'];

    public function __construct(
        private readonly CartManager $cart,
        private readonly CartQuote $quote,
        private readonly CheckoutDraft $draft,
        private readonly PaystackConfig $paystack,
        private readonly StockHold $hold,
    ) {}

    /** @return array<string, mixed>|null null when the cart is empty (the controller sends the shopper back to it) */
    public function for(Request $request): ?array
    {
        $user = $request->user();
        $lines = $this->cart->lines($user);

        if ($lines->isEmpty()) {
            return null;
        }

        // A signed-in person with a saved address starts on it: the radio shows it, so the quote must price for it too.
        if ($user !== null && ! array_key_exists('address_id', $this->draft->all())) {
            $starting = Address::query()->where('user_id', $user->id)->orderByDesc('is_default_shipping')->orderBy('id')->value('id');

            if ($starting !== null) {
                $this->draft->merge(['address_id' => (int) $starting]);
            }
        }

        $draft = $this->draft->all();
        $region = $this->draft->region($user);
        $quote = $this->draft->quote($this->quote, $lines, $user);

        $addresses = $user !== null ? Address::query()->where('user_id', $user->id)->orderByDesc('is_default_shipping')->orderBy('id')->get() : collect();
        $contactDone = $user !== null || filled($draft['email'] ?? null);
        $addressDone = $user !== null && filled($draft['address_id'] ?? null) && $draft['address_id'] !== 'new'
            ? $addresses->contains('id', (int) $draft['address_id'])
            : $this->typedAddressLooksComplete($draft['address'] ?? []);

        // Signed-in people with a default address start on it, so the common case is one press.
        $selectedAddress = $draft['address_id'] ?? ($addresses->firstWhere('is_default_shipping', true)?->id ?? $addresses->first()?->id ?? 'new');

        $savedAddressChosen = $user !== null && is_numeric($selectedAddress) && $addresses->contains('id', (int) $selectedAddress);

        // D-190: a saved address plus a delivery option for every parcel is a complete choice the page already shows with its total, so it
        // counts as confirmed without a separate press. This only decides what the page DRAWS; placing the order still validates the
        // address, re-reads every option from the seller's methods and recomputes the total against expected_total_cents.
        $everyParcelHasAnOption = $quote->groups !== [] && collect($quote->groups)->every(fn (array $g) => $g['can_deliver'] && $g['selected'] !== null);
        $autoConfirmed = $savedAddressChosen && $everyParcelHasAnOption;

        $deliveryDone = $contactDone && ($addressDone || $savedAddressChosen)
            && (! empty($draft['delivery_confirmed']) || $autoConfirmed) && $quote->canCheckout();

        $gatewayRequired = (bool) config('emall.checkout.requires_gateway');
        $gatewayClosed = $this->paystack->isMisconfigured() || ($gatewayRequired && ! $this->paystack->isUsable());

        return [
            'lines' => $lines,
            'quote' => $quote,
            'region' => $region,
            'draft' => $draft,
            'addresses' => $addresses,
            'selectedAddress' => $selectedAddress,
            'provinces' => AddressRules::PROVINCES,
            'signedIn' => $user !== null,
            'user' => $user,
            'contactDone' => $contactDone,
            'deliveryDone' => $deliveryDone,
            'autoConfirmed' => $autoConfirmed && empty($draft['delivery_confirmed']),
            'current' => ! $contactDone ? 1 : (! $deliveryDone ? 2 : 3),
            'steps' => self::STEPS,
            'parcelCount' => count($quote->groups),
            'token' => (string) Str::uuid(),
            'gatewayClosed' => $gatewayClosed,
            'paystackUsable' => $this->paystack->isUsable(),
            'testMode' => $this->paystack->isTestMode(),
            'currency' => $this->paystack->currency(),
            'holdMinutes' => $this->hold->windowMinutes(),
            'returnDays' => (int) config('emall.returns.window_days', 7),
            'phone' => $draft['phone'] ?? ($user?->phone ?? null),
        ];
    }

    /** @param  mixed  $address */
    private function typedAddressLooksComplete($address): bool
    {
        $address = is_array($address) ? $address : [];

        return filled($address['line1'] ?? null) && filled($address['city'] ?? null) && filled($address['postal_code'] ?? null) && filled($address['recipient_name'] ?? null);
    }
}
