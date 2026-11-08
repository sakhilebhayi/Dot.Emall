<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Addresses\AddressRules;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;
use App\Services\Cart\CartQuote;
use App\Services\Checkout\CartPage;
use App\Services\Checkout\CheckoutDraft;
use App\Services\Discovery\Wishlists;
use App\Support\Throttles;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use App\Services\CheckoutException;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The cart, for a guest (a session cart) and a signed-in person (cart_items) alike, through App\Services\Cart\CartManager. The page
 * is drawn by App\Services\Checkout\CartPage from a server-side quote; every control posts a REQUEST (a quantity, a code, a delivery
 * method) and the server decides. `checkout` below is the older single-form entry point for signed-in buyers, kept for callers that
 * still post to it; the checkout page (CheckoutController) is the front door.
 */
class CartController extends Controller
{
    public function index(Request $request, CartPage $page): View
    {
        return view('cart.index', $page->for($request));
    }

    public function store(Request $request, CartManager $cart): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::browsable()->findOrFail($validated['product_id']);

        $variant = isset($validated['variant_id'])
            ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($validated['variant_id'])
            : null;

        if ($variant === null) {
            $active = ProductVariant::query()->where('product_id', $product->id)->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->get();

            if ($active->count() > 1) {
                return back()->withErrors(['variant_id' => 'Choose '.$product->options()->pluck('name')->implode(' and ').' first.']);
            }

            $variant = $active->first() ?? abort(404);
        }

        try {
            $cart->add($request->user(), $variant, (int) ($validated['quantity'] ?? 1));
        } catch (CartProblem $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()->route('cart.index')->with('status', "Added \"{$product->name}\" to cart.");
    }

    public function update(Request $request, ProductVariant $variant, CartManager $cart): RedirectResponse
    {
        $this->mustBeInCart($request, $variant, $cart);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $cart->setQuantity($request->user(), $variant->id, (int) $validated['quantity']);
        } catch (CartProblem $e) {
            return redirect()->route('cart.index')->withErrors(['quantity' => $e->getMessage()])->with('quantity_variant', $variant->id);
        }

        return redirect()->route('cart.index')->with('status', 'Quantity updated.');
    }

    public function destroy(Request $request, ProductVariant $variant, CartManager $cart): RedirectResponse
    {
        $this->mustBeInCart($request, $variant, $cart);
        $cart->remove($request->user(), $variant->id);

        return redirect()->route('cart.index')->with('status', 'Item removed from cart.');
    }

    /** Moves a line to the wishlist: saved first, then taken out of the cart, so a failure never loses the item. */
    public function moveToWishlist(Request $request, ProductVariant $variant, CartManager $cart, Wishlists $wishlists): RedirectResponse
    {
        $this->mustBeInCart($request, $variant, $cart);

        $product = $variant->product()->firstOrFail();
        $wishlists->add($request->user(), $product, $variant->id);
        $cart->remove($request->user(), $variant->id);

        return redirect()->route('cart.index')->with('status', "Moved \"{$product->name}\" to your wishlist.");
    }

    /** Previews a promo code against this cart (no use is counted; checkout counts it) and keeps it for checkout when it applies. */
    public function applyPromo(Request $request, CartManager $cart, CartQuote $quote, CheckoutDraft $draft): RedirectResponse
    {
        $code = trim((string) $request->validate(['promo_code' => ['required', 'string', 'max:40']], [
            'promo_code.required' => 'Type a promo code first.',
        ])['promo_code']);

        $keys = [Throttles::promoUserKey($request->user()?->id ?? 'guest:'.sha1((string) $request->session()->getId())), Throttles::promoIpKey($request->ip())];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, Throttles::PROMO_MAX_FAILURES)) {
                return back()->withInput()->withErrors(['promo_code' => 'Too many promo code attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
            }
        }

        $lines = $cart->lines($request->user());
        $result = $quote->quote($lines, $draft->region($request->user()), $code, $draft->methods());

        if ($result->promo === null) {
            foreach ($keys as $key) {
                RateLimiter::hit($key, Throttles::PROMO_WINDOW_SECONDS);
            }

            return back()->withInput()->withErrors(['promo_code' => $result->promoProblem ?? 'That code is not valid for the items in your cart.']);
        }

        $draft->merge(['promo_code' => $result->promo->code]);

        return redirect()->route('cart.index')->with('status', 'Code '.$result->promo->code.' applied. The discount is in the total below.');
    }

    public function clearPromo(CheckoutDraft $draft): RedirectResponse
    {
        $draft->forget('promo_code');

        return redirect()->route('cart.index')->with('status', 'Promo code removed.');
    }

    /** The estimate form: where the parcels are going, for delivery prices before an address exists. */
    public function setRegion(Request $request, CheckoutDraft $draft): RedirectResponse
    {
        $data = $request->validate([
            'province' => ['required', Rule::in(AddressRules::PROVINCES)],
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
        ], ['province.required' => 'Choose a province.', 'province.in' => 'Choose one of the nine provinces.', 'postal_code.regex' => 'A South African postal code is four digits.']);

        $draft->merge(['region' => ['province' => $data['province'], 'postal_code' => $data['postal_code'] ?? null, 'country' => 'ZA']]);

        return redirect()->route('cart.index')->with('status', 'Delivery prices updated for '.$data['province'].'.');
    }

    /** One delivery method per seller (store id => method id). The server re-prices and refuses a method the store does not offer here. */
    public function setDelivery(Request $request, CartManager $cart, CartQuote $quote, CheckoutDraft $draft): RedirectResponse
    {
        $data = $request->validate(['shipping_methods' => ['required', 'array'], 'shipping_methods.*' => ['nullable', 'integer']]);
        $lines = $cart->lines($request->user());
        $result = $quote->quote($lines, $draft->region($request->user()), $draft->promo());
        $chosen = [];

        foreach ($data['shipping_methods'] as $storeId => $methodId) {
            $group = $result->groups[(int) $storeId] ?? null;

            if ($group !== null && $methodId !== null && collect($group['options'])->contains(fn ($o) => $o->methodId === (int) $methodId)) {
                $chosen[(int) $storeId] = (int) $methodId;
            }
        }

        $draft->merge(['shipping_methods' => $chosen + $draft->methods()]);

        return redirect()->route('cart.index')->with('status', 'Delivery choice updated.');
    }

    public function acceptPrices(Request $request, CartPage $page): RedirectResponse
    {
        $page->acceptPrices($request->user());

        return redirect()->route('cart.index')->with('status', 'Prices accepted. The total below is what you will pay.');
    }

    private function mustBeInCart(Request $request, ProductVariant $variant, CartManager $cart): void
    {
        abort_unless($cart->lines($request->user())->contains(fn ($l) => $l->variant->id === $variant->id), 404);
    }

    /**
     * Places an order from the current user's cart. The work -- stock locks,
     * promo handling, line snapshots, the after-commit event -- lives in
     * App\Services\CheckoutService. With Paystack configured the buyer is
     * then sent to Paystack to pay (a failure to reach it leaves a saved,
     * unpaid order with a Pay now button); without it no payment is taken and
     * the order is placed "pending, unpaid", as before.
     */
    public function checkout(Request $request, CheckoutService $checkout, PaymentService $payments, PaystackConfig $paystack): RedirectResponse
    {
        $validated = $request->validate([
            'checkout_token' => ['required', 'uuid'],
            'shipping_name' => ['nullable', 'string', 'max:120'],
            'shipping_address' => ['required_without_all:address_id,address', 'nullable', 'string', 'max:255'],
            'address_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'array'],
            'shipping_methods' => ['nullable', 'array'],
            'shipping_methods.*' => ['nullable', 'integer'],
            // The total the buyer was shown; a different server-side total stops the order (PriceChangedException).
            'expected_total_cents' => ['nullable', 'integer', 'min:0'],
            'shipping_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\-.\s]{5,40}$/'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        // A key that must not be used (a live key without the second switch)
        // stops checkout before an order exists: no stock is held for a
        // payment that cannot be taken. Operators see why on their desk.
        if ($paystack->isMisconfigured()) {
            Log::error('Checkout stopped: the Paystack configuration was refused.');

            return redirect()->route('cart.index')->with('error', 'Card payment is not available right now, so your order has not been placed and nothing was taken. Please try again later.');
        }

        try {
            $order = $checkout->place($request->user(), $validated, $validated['checkout_token'], $request->ip());
        } catch (CheckoutException $e) {
            // A PriceChangedException lands here too: the message carries the new total and the cart page redraws with it.
            return redirect()->route('cart.index')->with('error', $e->getMessage());
        }

        if (! $order->wasRecentlyCreated) {
            return redirect()->route('orders.show', $order)
                ->with('status', 'This order was already placed, so nothing was ordered twice. Here it is.');
        }

        if (! $order->requires_payment) {
            return redirect()->route('orders.show', $order)->with('status', 'Order placed. It is pending and unpaid: payment is not available yet.');
        }

        try {
            $payment = $payments->startPayment($order);
        } catch (InvalidArgumentException|PaymentGatewayException $e) {
            return redirect()->route('orders.show', $order)->with('error', $e instanceof PaymentGatewayException
                ? OrderPaymentController::gatewayMessage($e)
                : $e->getMessage());
        }

        if ($order->refresh()->payment_status->isSettled()) {
            return redirect()->route('orders.show', $order)->with('status', 'Order placed. There was nothing to pay on it, so it is already with the seller.');
        }

        return redirect()->away($payment->authorization_url);
    }
}
