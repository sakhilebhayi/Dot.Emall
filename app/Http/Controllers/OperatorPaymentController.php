<?php

namespace App\Http\Controllers;

use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Operator\Concerns\AnswersDecisions;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Services\Operator\OperatorDecisions;
use App\Services\Paystack\PaystackConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    use AnswersDecisions;

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
            'paidTwice' => $this->paidTwice(),
        ]);
    }

    /** The payment events log: every notice Paystack (webhook or callback) gave us and what we did with it. */
    public function events(Request $request): View
    {
        $outcome = in_array($request->query('outcome'), ['applied', 'duplicate', 'unmatched', 'needs_review'], true) ? $request->query('outcome') : null;

        return view('operator.payments.events', [
            'events' => PaymentEvent::query()->when($outcome, fn ($q) => $q->where('outcome', $outcome))->latest('id')->paginate(30)->withQueryString(),
            'outcome' => $outcome,
            'counts' => PaymentEvent::query()->selectRaw('outcome, count(*) as n')->groupBy('outcome')->pluck('n', 'outcome'),
        ]);
    }

    /**
     * Orders with more than one successful payment: the duplicate-payment indicator. Each extra successful charge is money the buyer paid twice.
     *
     * @return Collection<int, object{order_id: int, payments: int, total_cents: int}>
     */
    private function paidTwice()
    {
        return Payment::query()->where('status', PaymentAttemptStatus::Success->value)->whereNotNull('order_id')
            ->groupBy('order_id')->havingRaw('count(*) > 1')
            ->selectRaw('order_id, count(*) as payments, sum(amount_cents) as total_cents')->orderByDesc('order_id')->limit(25)->get();
    }

    public function review(Request $request, Payment $payment, OperatorDecisions $decisions): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return $this->attempt('payment-'.$payment->id, fn () => $decisions->reviewPayment($payment, $request->user(), $validated['note']), 'Review recorded.', route('operator.payments.index'));
    }

    public function retryRefund(Refund $refund, Request $request, OperatorDecisions $decisions): RedirectResponse
    {
        $refund = $decisions->retryRefund($refund, $request->user());

        return redirect()->route('operator.payments.index')->with(
            $refund->status === RefundStatus::Pending ? 'error' : 'status',
            $refund->status === RefundStatus::Pending
                ? 'Paystack did not accept the refund: '.($refund->last_error ?? 'no reason given').' Refund it in the Paystack dashboard, then mark it done.'
                : 'Refund request sent to Paystack. It is not complete until Paystack reports it processed.',
        );
    }

    public function markRefundDone(Request $request, Refund $refund, OperatorDecisions $decisions): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        return $this->attempt('refund-'.$refund->id, fn () => $decisions->markRefundDone($refund, $request->user(), $validated['note']), 'Marked as refunded by you.', route('operator.payments.index'));
    }
}
