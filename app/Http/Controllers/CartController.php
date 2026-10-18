<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
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
        $items = CartItem::with(['product.store', 'product.images'])->get();

        $subtotal = $items->sum(fn (CartItem $item) => $item->quantity * $item->product->price);

        // Lines that cannot be bought as they stand: the listing was
        // switched off / its store closed, or stock dropped below the
        // quantity in the cart. Shown per line, and they hold back checkout
        // until the buyer fixes them (the server re-checks regardless).
        $problems = [];
        foreach ($items as $item) {
            $product = $item->product;

            if (! $product->is_active || $product->status !== 'approved' || ! $product->store->is_active) {
                $problems[$item->id] = 'No longer available. Remove it to continue.';
            } elseif ($item->quantity > $product->stock) {
                $problems[$item->id] = $product->stock === 0
                    ? 'Out of stock now. Remove it to continue.'
                    : "Only {$product->stock} left. Lower the quantity to continue.";
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ]);

        $product = Product::browsable()->findOrFail($validated['product_id']);
        $quantity = $validated['quantity'] ?? 1;

        if ($product->store->owner_id === $request->user()->id) {
            return back()->withErrors(['product_id' => 'This is your own listing, so you cannot buy it.']);
        }

        $item = CartItem::firstOrNew([
            'user_id' => auth()->id(),
            'product_id' => $product->id,
        ]);
        $already = $item->exists ? $item->quantity : 0;

        if ($error = $this->quantityProblem($product, $already + $quantity, $already)) {
            return back()->withErrors(['quantity' => $error]);
        }

        $item->quantity = $already + $quantity;
        $item->save();

        return redirect()->route('cart.index')->with('status', "Added \"{$product->name}\" to cart.");
    }

    public function update(Request $request, CartItem $cartItem): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        $product = $cartItem->product;

        if (! $product->is_active || $product->status !== 'approved' || ! $product->store->is_active) {
            return back()->withErrors(['quantity' => "\"{$product->name}\" is no longer available. Remove it from your cart."]);
        }

        if ($error = $this->quantityProblem($product, (int) $validated['quantity'], 0)) {
            return back()->withErrors(['quantity' => $error]);
        }

        $cartItem->update(['quantity' => $validated['quantity']]);

        return redirect()->route('cart.index')->with('status', 'Quantity updated.');
    }

    /**
     * Null when $wanted of the product can be held in a cart; otherwise a
     * plain-words reason. $already is how many the buyer holds now, only to
     * word the message when they are topping up.
     */
    private function quantityProblem(Product $product, int $wanted, int $already): ?string
    {
        if ($product->stock < 1) {
            return "\"{$product->name}\" is out of stock.";
        }

        if ($wanted > $product->stock) {
            return $already > 0
                ? "Only {$product->stock} of \"{$product->name}\" in stock, and you already have {$already} in your cart."
                : "Only {$product->stock} of \"{$product->name}\" in stock.";
        }

        if ($wanted > 99) {
            return 'You can order at most 99 of one item at a time.';
        }

        return null;
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
            'shipping_address' => ['required', 'string', 'max:255'],
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
