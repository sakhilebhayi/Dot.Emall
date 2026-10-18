<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentService;
use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The buyer's two touch points with Paystack for an order they placed:
 * "Pay now" (start or resume payment, then go to Paystack) and the return
 * from Paystack. Both are for the order's buyer ONLY: anyone else, sellers
 * and operators included, gets a 404, as if the order did not exist.
 */
class OrderPaymentController extends Controller
{
    public function pay(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->ownOrFail($request, $order);

        $back = redirect()->route('orders.show', $order);

        try {
            $payment = $payments->startPayment($order);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        } catch (PaymentGatewayException $e) {
            return $back->with('error', $this->gatewayMessage($e));
        }

        $order->refresh();

        if ($order->payment_status->isSettled()) {
            return $back->with('status', 'Payment received. Thank you: your order is now with the seller.');
        }

        return redirect()->away($payment->authorization_url);
    }

    /**
     * Where Paystack sends the buyer back. The query string is only used to
     * choose which of THIS order's own attempts to check; whether the order
     * is paid is decided by asking Paystack, never by the URL.
     */
    public function callback(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->ownOrFail($request, $order);

        $reference = $request->query('reference', $request->query('trxref'));
        $result = $payments->confirmReturn($order, is_string($reference) ? $reference : null);

        return redirect()->route('orders.show', $order)
            ->with($result->isGood() ? 'status' : 'error', $result->message());
    }

    private function ownOrFail(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
    }

    /**
     * What a buyer is told when Paystack could not be used. Operators see
     * the precise config problem on their desk, not the buyer.
     */
    public static function gatewayMessage(PaymentGatewayException $e): string
    {
        if ($e->isCurrencyMismatch()) {
            return $e->getMessage();
        }

        $minutes = app(PaystackConfig::class)->unpaidWindowMinutes();

        return $e->isTemporary()
            ? "We could not reach Paystack just now. Your order is saved: press Pay now to try again. An order that is not paid within {$minutes} minutes is cancelled."
            : 'Card payment is not available right now. Your order is saved; try Pay now again shortly. An order that is not paid within '.$minutes.' minutes is cancelled.';
    }
}
