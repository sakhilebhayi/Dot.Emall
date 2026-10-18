<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Services\Paystack\PaystackConfig;
use App\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The operator's payments desk (/operator/payments): everything Paystack
 * produced that a person has to look at.
 *   - payments that need review (money for a cancelled order, a second
 *     payment, a mismatch),
 *   - refunds owed to buyers that Paystack has not accepted,
 *   - unmatched Paystack events,
 *   - and the gateway's own state (not configured / test mode / refused key).
 * Routes sit behind the `operator` middleware.
 */
class OperatorPaymentController extends Controller
{
    public function index(PaystackConfig $config): View
    {
        return view('operator.payments.index', [
            'gateway' => [
                'configured' => $config->isConfigured(),
                'testMode' => $config->isTestMode(),
                'problem' => $config->problem(),
                'currency' => $config->currency(),
            ],
            'reviews' => Payment::query()
                ->with(['order.user:id,name', 'events'])
                ->where('status', PaymentAttemptStatus::NeedsReview->value)
                ->whereNull('reviewed_at')
                ->latest('id')
                ->limit(50)
                ->get(),
            'refundsToAction' => Refund::query()
                ->with(['order', 'payment'])
                ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Failed->value])
                ->orderBy('id')
                ->limit(50)
                ->get(),
            'refundsInFlight' => Refund::query()
                ->with('order')
                ->where('status', RefundStatus::Submitted->value)
                ->orderBy('id')
                ->limit(50)
                ->get(),
            'unmatched' => PaymentEvent::query()
                ->where('outcome', 'unmatched')
                ->latest('id')
                ->limit(25)
                ->get(),
        ]);
    }

    public function review(Request $request, Payment $payment): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $payment->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'review_note' => $validated['note'],
        ])->save();

        return redirect()->route('operator.payments.index')->with('status', 'Review recorded.');
    }

    public function retryRefund(Refund $refund, RefundService $refunds): RedirectResponse
    {
        if ($refund->status === RefundStatus::Failed) {
            // Paystack gave up on it; try the API again from the start.
            $refund->forceFill(['status' => RefundStatus::Pending, 'provider_refund_id' => null])->save();
        }

        $refund = $refunds->submit($refund);

        return redirect()->route('operator.payments.index')->with(
            $refund->status === RefundStatus::Pending ? 'error' : 'status',
            $refund->status === RefundStatus::Pending
                ? 'Paystack did not accept the refund: '.($refund->last_error ?? 'no reason given').' Refund it in the Paystack dashboard, then mark it done.'
                : 'Refund request sent to Paystack. It is not complete until Paystack reports it processed.',
        );
    }

    public function markRefundDone(Request $request, Refund $refund, RefundService $refunds): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $refunds->markDoneByOperator($refund, $request->user(), $validated['note']);

        return redirect()->route('operator.payments.index')->with('status', 'Marked as refunded by you.');
    }
}
