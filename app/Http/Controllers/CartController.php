<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartManager;
use App\Services\Cart\CartProblem;
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
 * Cart and the checkout entry point. CartItem applies
 * App\Models\Concerns\HasUserScope, so every cart query is already scoped
 * to the signed-in user and another user's cart line 404s on route-model
 * binding. user_id is still set explicitly at create time.
 */
class CartController extends Controller
{
    public function index(PaystackConfig $paystack): View
    {
        $items = CartItem::with(['product.store', 'product.images', 'variant'])->get();

        $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * ($item->variant ?? $item->product)->price);

        // Lines that cannot be bought as they stand: the listing was
        // switched off / its store closed, or stock dropped below the
        // quantity in the cart. Shown per line, and they hold back checkout
        // until the buyer fixes them (the server re-checks regardless).
        $problems = [];
        foreach ($items as $item) {
            $product = $item->product;
            $stock = $item->variant?->stock ?? $product->stock;

            if (! $product->is_active || $product->status !== 'approved' || ! $product->store->is_active || ($item->variant && ! $item->variant->is_active)) {
                $problems[$item->id] = 'No longer available. Remove it to continue.';
            } elseif ($item->quantity > $stock) {
                $problems[$item->id] = $stock === 0
                    ? 'Out of stock now. Remove it to continue.'
                    : "Only {$stock} left. Lower the quantity to continue.";
            }
        }

        return view('cart.index', [
            'items' => $items,
            'subtotal' => $subtotal,
            'problems' => $problems,
            // One fresh token per page render: the single-use key for this
            // submission of the checkout form (see CheckoutService::place()).
            'checkoutToken' => (string) Str::uuid(),
            'paystack' => [
                'enabled' => $paystack->isUsable(),
                'refused' => $paystack->isMisconfigured(),
                'testMode' => $paystack->isTestMode(),
                'currency' => $paystack->currency(),
                'windowMinutes' => $paystack->unpaidWindowMinutes(),
            ],
        ]);
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

    public function update(Request $request, CartItem $cartItem, CartManager $cart): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        try {
            $cart->setQuantity($request->user(), (int) $cartItem->variant_id, (int) $validated['quantity']);
        } catch (CartProblem $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        return redirect()->route('cart.index')->with('status', 'Quantity updated.');
    }

    public function destroy(CartItem $cartItem): RedirectResponse
    {
        $cartItem->delete();

        return redirect()->route('cart.index')->with('status', 'Item removed from cart.');
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
