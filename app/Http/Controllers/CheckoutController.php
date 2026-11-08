<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Order;
use App\Services\Addresses\AddressBook;
use App\Services\Addresses\AddressRules;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartQuote;
use App\Services\Catalogue\OutOfStock;
use App\Services\Checkout\CheckoutDraft;
use App\Services\Checkout\CheckoutPage;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\Discovery\Track;
use App\Services\Guests\GuestOrderAccess;
use App\Services\PaymentService;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\PriceChangedException;
use App\Services\PromoRejectedException;
use App\Support\Actor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The one-page checkout for guests and signed-in people (D-9). Three actions:
 *   show      draws the page from the session draft, the cart and the address book;
 *   delivery  saves contact and address, prices the parcels for that place and confirms the delivery step (works without script);
 *   place     validates everything on the server, places the order through CheckoutService, then sends the buyer to Paystack.
 * Whatever is typed is kept in the draft BEFORE it is validated, so an error never costs anyone their address.
 */
class CheckoutController extends Controller
{
    public function show(Request $request, CheckoutPage $page, Track $track): View|RedirectResponse
    {
        $data = $page->for($request);

        if ($data === null) {
            return redirect()->route('cart.index')->with('warning', 'Your cart is empty, so there is nothing to check out.');
        }

        // A line that cannot be bought as it stands is fixed in the cart, where it is explained, never papered over here.
        if ($data['quote']->problems !== []) {
            return redirect()->route('cart.index')->with('error', 'Something in your cart needs fixing before you can check out. It is marked below.');
        }

        // D-153: CommerceMetrics reads "checkout started" for the checkout-completion rate, but nothing ever wrote it, so the rate
        // could never have a denominator. One record per person per day is all the metric counts (it counts person-days).
        $track->checkoutStarted(Actor::forRequest($request));

        return view('checkout.show', $data);
    }

    public function delivery(Request $request, CartManager $cart, CartQuote $quote, CheckoutDraft $draft): RedirectResponse
    {
        $this->remember($request, $draft);
        $this->validated($this->validateDestination($request));

        if ($cart->lines($request->user())->isEmpty()) {
            return redirect()->route('cart.index');
        }

        $problem = null;
        $promoFailed = false;

        if ($draft->promo() !== null) {
            $preview = $quote->quote($cart->lines($request->user()), $draft->region($request->user()), $draft->promo(), $draft->methods());

            if ($preview->promo === null) {
                $promoFailed = true;
                $problem = $preview->promoProblem ?? 'That promo code is not valid for the items in your cart.';
                $draft->forget('promo_code');
            }
        }

        $result = $draft->quote($quote, $cart->lines($request->user()), $request->user());
        $stuck = collect($result->groups)->first(fn ($g) => ! $g['can_deliver']);

        if ($stuck !== null) {
            $draft->merge(['delivery_confirmed' => false]);

            return redirect()->route('checkout.show')->withInput()->withErrors(['delivery' => $stuck['store_name'].' does not deliver to that address. Change the address, or go back to the cart and remove their items.']);
        }

        if ($result->problems !== []) {
            $draft->merge(['delivery_confirmed' => false]);

            return redirect()->route('cart.index')->with('error', 'Something in your cart changed. Fix the marked items, then come back.');
        }

        $draft->merge(['delivery_confirmed' => true]);

        $redirect = redirect()->to(route('checkout.show').'#review')->with('status', 'Delivery options are ready. Check the total, then pay.');

        return $promoFailed ? $redirect->withInput()->withErrors(['promo_code' => $problem]) : $redirect;
    }

    public function place(Request $request, CartManager $cart, CheckoutService $checkout, PaymentService $payments, CheckoutDraft $draft, AddressBook $book, GuestOrderAccess $guestAccess): RedirectResponse
    {
        $this->remember($request, $draft);
        $user = $request->user();

        $validator = $this->validateDestination($request);
        $validator->addRules([
            'checkout_token' => ['required', 'uuid'],
            'expected_total_cents' => ['required', 'integer', 'min:0'],
            'terms' => ['accepted'],
            'shipping_methods' => ['nullable', 'array'],
            'shipping_methods.*' => ['nullable', 'integer'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);
        $input = $this->validated($validator);

        $lines = $cart->lines($user);
        $region = $draft->region($user, $this->typed($request));

        $destination = ['shipping_methods' => $input['shipping_methods'] ?? [], 'expected_total_cents' => (int) $input['expected_total_cents'], 'promo_code' => $input['promo_code'] ?? null];

        if ($user !== null && ($input['address_id'] ?? 'new') !== 'new' && filled($input['address_id'] ?? null)) {
            $destination['address_id'] = (int) $input['address_id'];
            $destination['shipping_phone'] = $input['phone'] ?? null;
        } else {
            $destination['address'] = $input['address'];
            $destination['address']['phone'] = $input['address']['phone'] ?? $input['phone'] ?? null;
            $destination['shipping_phone'] = $input['phone'] ?? ($input['address']['phone'] ?? null);
        }

        try {
            $order = $user !== null
                ? $checkout->place($user, $destination, $input['checkout_token'], $request->ip())
                : $checkout->placeAsGuest((string) $input['email'], $lines->map(fn ($l) => ['variant_id' => $l->variant->id, 'quantity' => $l->quantity])->all(), $destination, $input['checkout_token'], $request->ip());
        } catch (PromoRejectedException $e) {
            return redirect()->route('checkout.show')->withInput()->withErrors(['promo_code' => $e->getMessage()]);
        } catch (PriceChangedException $e) {
            $draft->merge(['delivery_confirmed' => true]);

            return redirect()->route('checkout.show')->withInput()->with('price_changed', ['from' => $e->expectedCents, 'to' => $e->actualCents])->with('error', $e->getMessage());
        } catch (OutOfStock $e) {
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        } catch (CheckoutException $e) {
            return redirect()->route('checkout.show')->withInput()->with('error', $e->getMessage());
        }

        if (! $order->wasRecentlyCreated) {
            return $this->after($request, $order, $draft, $guestAccess)->with('status', 'This order was already placed, so nothing was ordered twice. Here it is.');
        }

        if ($user !== null && $request->boolean('save_address') && isset($destination['address'])) {
            try {
                $book->add($user, $destination['address'] + ['country' => 'ZA']);
            } catch (ValidationException) {
                // The order is placed; a full address book must not undo it.
            }
        }

        $cart->clear($user);
        $response = $this->after($request, $order, $draft, $guestAccess);

        if (! $order->requires_payment) {
            return $response->with('status', 'Order placed. It is pending and unpaid: payment is not available yet.');
        }

        try {
            $payment = $payments->startPayment($order);
        } catch (InvalidArgumentException|PaymentGatewayException $e) {
            return $response->with('error', $e instanceof PaymentGatewayException ? OrderPaymentController::gatewayMessage($e) : $e->getMessage());
        }

        if ($order->refresh()->payment_status->isSettled()) {
            return $response->with('status', 'Order placed. There was nothing to pay on it, so it is already with the seller.');
        }

        return redirect()->away($payment->authorization_url);
    }

    /** Where a placed order lives: the account's order page, or the guest's own (granted for this browser). */
    private function after(Request $request, Order $order, CheckoutDraft $draft, GuestOrderAccess $guestAccess): RedirectResponse
    {
        $draft->forget();

        if ($order->isGuest()) {
            $guestAccess->grantSession($order);

            return redirect()->route('guest.orders.show', $order);
        }

        return redirect()->route('orders.show', $order);
    }

    /** What was typed, in the shape the draft keeps. @return array<string, mixed> */
    private function typed(Request $request): array
    {
        return array_filter([
            'email' => $request->input('email') !== null ? mb_strtolower(trim((string) $request->input('email'))) : null,
            'phone' => $request->input('phone'),
            'address_id' => $request->input('address_id'),
            'address' => is_array($request->input('address')) ? $request->input('address') : null,
            'save_address' => $request->boolean('save_address'),
            'shipping_methods' => is_array($request->input('shipping_methods')) ? $request->input('shipping_methods') : null,
            'promo_code' => $request->has('promo_code') ? trim((string) $request->input('promo_code')) : null,
        ], fn ($v) => $v !== null);
    }

    private function remember(Request $request, CheckoutDraft $draft): void
    {
        $typed = $this->typed($request);

        // A changed address needs the delivery step confirmed again.
        if (isset($typed['address']) || isset($typed['address_id'])) {
            $before = [$draft->get('address'), $draft->get('address_id')];

            if ($before !== [$typed['address'] ?? null, $typed['address_id'] ?? null]) {
                $typed['delivery_confirmed'] = false;
            }
        }

        $draft->merge($typed);
    }

    /** @return array<string, mixed> */
    private function validated(\Illuminate\Validation\Validator $validator): array
    {
        try {
            return $validator->validate();
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('checkout.show'));
        }
    }

    /** Contact and destination rules shared by "update delivery options" and "pay". */
    private function validateDestination(Request $request): \Illuminate\Validation\Validator
    {
        $user = $request->user();
        $rules = ['phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/']];

        if ($user === null) {
            $rules['email'] = ['required', 'email:rfc', 'max:190'];
        }

        $usesSaved = $user !== null && filled($request->input('address_id')) && $request->input('address_id') !== 'new';

        if ($usesSaved) {
            $rules['address_id'] = ['required', 'integer', function ($attribute, $value, $fail) use ($user) {
                if (! Address::query()->where('user_id', $user->id)->whereKey((int) $value)->exists()) {
                    $fail('That saved address was not found. Choose another.');
                }
            }];
        } else {
            $rules['address_id'] = ['nullable'];
            $rules['address'] = ['required', 'array'];
            $rules += AddressRules::for((array) $request->input('address', []), 'address.');
            unset($rules['address.is_default_shipping'], $rules['address.is_default_billing']);
        }

        $validator = Validator::make($request->all(), $rules, [
            'terms.accepted' => 'Tick the box to confirm you have read the terms and the returns summary.',
            'email.required' => 'Enter your e-mail address so we can send your order link.',
            'email.email' => 'That e-mail address does not look right.',
            'address.required' => 'Enter a delivery address.',
            'address.recipient_name.required' => 'Who should the parcel be addressed to?',
            'address.line1.required' => 'Enter the street address.',
            'address.city.required' => 'Enter the city or town.',
            'address.province.required' => 'Choose a province.',
            'address.province.in' => 'Choose one of the nine provinces.',
            'address.postal_code.required' => 'Enter the postal code.',
            'address.postal_code.regex' => 'A South African postal code is four digits.',
        ] + AddressRules::messages('address.'));

        return $validator;
    }
}
