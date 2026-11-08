<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesBuyerOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Cart\CartManager;
use App\Services\OrderAccess;
use App\Services\OrderLifecycle;
use App\Services\Orders\OrderMessages;
use App\Services\Orders\OrderPage;
use App\Services\Orders\Reorder;
use App\Services\PaymentService;
use App\Services\Paystack\PaymentGatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * What the BUYER of an order can do from its page, for an account (/orders/...) and a guest (/guest/orders/...) alike: pay, check a payment
 * again, confirm receipt, cancel an unsent item, write to a seller, report a problem, buy again, and open the printable receipt. Who the
 * buyer is, is decided in one place (ResolvesBuyerOrder). Every state rule lives in the services (OrderLifecycle, PaymentService).
 */
class OrderActionsController extends Controller
{
    use ResolvesBuyerOrder;

    public function pay(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->buyerOf($request, $order);
        $back = $this->orderPageRoute($request, $order);

        try {
            $payment = $payments->startPayment($order);
        } catch (InvalidArgumentException $e) {
            return $back->with('error', $e->getMessage());
        } catch (PaymentGatewayException $e) {
            return $back->with('error', OrderPaymentController::gatewayMessage($e));
        }

        if ($order->refresh()->payment_status->isSettled()) {
            return $back->with('status', 'Payment received. Thank you: your order is now with the seller.')->with('payment_outcome', 'paid');
        }

        return redirect()->away($payment->authorization_url);
    }

    /** Asks Paystack again about this order's open attempt. The answer, never the browser, decides. */
    public function checkPayment(Request $request, Order $order, PaymentService $payments): RedirectResponse
    {
        $this->buyerOf($request, $order);
        $result = $payments->confirmReturn($order, null);

        return $this->orderPageRoute($request, $order)->with($result->isGood() ? 'status' : 'error', $result->message())->with('payment_outcome', $result->outcome);
    }

    public function receive(Request $request, Order $order, OrderItem $item, OrderLifecycle $lifecycle): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);

        try {
            $lifecycle->confirmReceipt($item, $buyer);
        } catch (InvalidArgumentException $e) {
            return $this->orderPageRoute($request, $order)->with('error', $e->getMessage());
        }

        $days = (int) config('emall.returns.window_days', 7);

        return $this->orderPageRoute($request, $order)->with('status', "Thank you. Your {$days}-day return period starts today.");
    }

    /** A guest cancels an unsent item (a signed-in buyer uses order-items.cancel). */
    public function cancel(Request $request, Order $order, OrderItem $item, OrderLifecycle $lifecycle): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);

        try {
            $lifecycle->cancelItem($item, $buyer, null, $buyer === null ? 'guest' : null);
        } catch (InvalidArgumentException $e) {
            return $this->orderPageRoute($request, $order)->with('error', $e->getMessage());
        }

        return $this->orderPageRoute($request, $order)->with('status', 'Item cancelled. It goes back into stock and the order total is recalculated.');
    }

    public function message(Request $request, Order $order, OrderMessages $messages): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);
        $data = $request->validate(['group' => ['required', 'integer'], 'body' => ['required', 'string', 'max:2000']], ['body.required' => 'Write your message first.', 'group.required' => 'Choose which seller this is for.']);
        $group = $order->groups()->whereKey((int) $data['group'])->first();
        abort_if($group === null, 404);

        try {
            $messages->toSeller($order, $group, $buyer, $data['body']);
        } catch (ValidationException $e) {
            return $this->orderPageRoute($request, $order)->withInput()->withErrors($e->errors(), 'message')->with('open_message', $group->id);
        }

        return $this->orderPageRoute($request, $order)->with('status', "Sent to {$group->store_name}. They will reply by e-mail to ".$order->buyerEmail().'.');
    }

    public function report(Request $request, Order $order, OrderMessages $messages): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']], ['body.required' => 'Tell us what went wrong.']);

        try {
            $messages->reportProblem($order, $buyer, $data['body']);
        } catch (ValidationException $e) {
            return $this->orderPageRoute($request, $order)->withInput()->withErrors($e->errors(), 'report')->with('open_report', true);
        }

        return $this->orderPageRoute($request, $order)->with('status', 'Reported to the Emall team. They can see this order. If they need more from you, they will write to '.$order->buyerEmail().'.');
    }

    public function reorder(Request $request, Order $order, Reorder $reorder): RedirectResponse
    {
        $buyer = $this->buyerOf($request, $order);
        $result = $reorder->into($order, $buyer);

        return redirect()->route('cart.index')->with($result['added'] === [] && $result['partial'] === [] ? 'error' : 'status', Reorder::sentence($result));
    }

    /** The printable receipt. It is a receipt of what was paid, not a VAT tax invoice (none is issued). */
    public function receipt(Request $request, Order $order, OrderAccess $access, OrderPage $page, CartManager $cart): View
    {
        $buyer = $this->buyerOf($request, $order);
        $view = $buyer !== null ? $access->present($order, $buyer) : $access->presentGuest($order);

        return view('orders.receipt', $page->for($order, $view) + ['mode' => $buyer !== null ? 'account' : 'guest']);
    }
}
