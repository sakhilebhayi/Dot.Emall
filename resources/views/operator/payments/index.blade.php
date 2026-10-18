@php
    use App\Enums\RefundStatus;

    $money = fn (int $cents, ?string $currency = null) => ($currency ?? $gateway['currency']).' '.number_format($cents / 100, 2);
    $reasons = [
        'order_cancelled' => 'Payment received for a cancelled order. The order was not re-opened and its stock was not taken again. Refund the buyer in the Paystack dashboard, or contact them to place the order again.',
        'order_already_paid' => 'A second payment arrived for an order that was already paid. Refund the extra payment in the Paystack dashboard.',
        'underpaid' => 'The payment is less than the order total. Check it in the Paystack dashboard.',
        'amount_mismatch' => 'The amount Paystack reports is not the amount we asked for. The order was not marked paid.',
        'currency_mismatch' => 'The currency Paystack reports is not the one we asked for. The order was not marked paid.',
        'reference_mismatch' => 'Paystack answered with a different reference from the one we asked about. The order was not marked paid.',
        'transaction_already_applied' => 'This Paystack transaction was already applied to another payment. The order was not marked paid.',
        'unknown_reference' => 'A charge for a reference we never issued.',
        'unknown_refund' => 'A refund event for a refund we have no record of.',
        'malformed_payload' => 'A charge event with missing fields.',
        'order_missing' => 'A charge for an order that no longer exists.',
    ];

    $needAction = $reviews->count() + $refundsToAction->count();

    if (! $gateway['configured']) {
        $stateLine = 'Paystack is not configured: checkout takes no payment and orders are placed pending and unpaid.';
    } elseif ($gateway['problem']) {
        $stateLine = 'Paystack key refused: checkout is stopped. See below.';
    } elseif ($needAction > 0) {
        $stateLine = $needAction.' '.\Illuminate\Support\Str::plural('item', $needAction).' need you.';
    } else {
        $stateLine = 'Nothing needs you.';
    }

    $label = 'font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;';
    $th = 'padding:0.7rem 1.1rem;text-align:left;'.$label;
    $td = 'padding:0.8rem 1.1rem;font-size:0.78rem;color:#a1a1aa;vertical-align:top;';
@endphp
<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:1100px;">

    <div style="margin-bottom:1.5rem;">
        <h1 style="margin:0;font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;">Payments desk</h1>
        <p style="margin:0.35rem 0 0;font-size:0.9rem;font-weight:600;color:{{ ($needAction > 0 || $gateway['problem']) ? '#f59e0b' : '#a1a1aa' }};" role="status">{{ $stateLine }}</p>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">{{ session('status') }}</div>
    @endif
    @if (session('error'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;" role="alert">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;" role="alert">
        @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    {{-- Gateway state: shared edges, one ruled readout --}}
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;background:rgba(255,255,255,0.09);border:1px solid rgba(255,255,255,0.09);border-radius:4px;overflow:hidden;margin-bottom:2rem;">
        <div style="background:#141416;padding:1rem 1.2rem;">
            <div style="{{ $label }}">Gateway</div>
            <div style="margin-top:0.35rem;font-size:0.9rem;font-weight:700;color:#f4f4f5;">
                @if(! $gateway['configured']) Not configured
                @elseif($gateway['problem']) Refused
                @elseif($gateway['testMode']) <span style="color:#f59e0b;">TEST MODE</span>
                @else Live @endif
            </div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.3rem;">{{ $gateway['problem'] ?? ($gateway['configured'] ? 'Charging in '.$gateway['currency'].'.' : 'Set PAYSTACK_SECRET_KEY (a test key) to enable card payments.') }}</div>
        </div>
        <div style="background:#141416;padding:1rem 1.2rem;">
            <div style="{{ $label }}">Payments to review</div>
            <div class="metric-val" style="font-size:1.4rem;color:{{ $reviews->isNotEmpty() ? '#f59e0b' : '#f4f4f5' }};margin-top:0.35rem;">{{ $reviews->count() }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.3rem;">Money that did not fit an order.</div>
        </div>
        <div style="background:#141416;padding:1rem 1.2rem;">
            <div style="{{ $label }}">Refunds owed</div>
            <div class="metric-val" style="font-size:1.4rem;color:{{ $refundsToAction->isNotEmpty() ? '#f59e0b' : '#f4f4f5' }};margin-top:0.35rem;">{{ $refundsToAction->count() }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.3rem;">{{ $refundsInFlight->count() }} with Paystack, not yet confirmed.</div>
        </div>
    </div>

    {{-- Payments needing review --}}
    <h2 style="margin:0 0 0.8rem;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Needs review</h2>
    <div class="dot-card" style="overflow:hidden;margin-bottom:2rem;">
        @forelse($reviews as $payment)
        @php $reason = $payment->events->sortByDesc('id')->pluck('reason')->filter()->first(); @endphp
        <div style="padding:1rem 1.25rem;{{ ! $loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div>
                    <div style="font-size:0.85rem;font-weight:700;color:#f4f4f5;">
                        @if($payment->order)<a href="{{ route('orders.show', $payment->order) }}" style="color:inherit;text-decoration:none;">Order {{ $payment->order->reference() }}</a>@else Order removed @endif
                        · {{ $money($payment->amount_cents, $payment->currency) }}
                    </div>
                    <div style="font-size:0.7rem;color:#71717a;margin-top:0.2rem;font-family:'JetBrains Mono',monospace;">{{ $payment->reference }}</div>
                </div>
                <div style="font-size:0.7rem;color:#71717a;">{{ $payment->updated_at->format('d M Y, H:i') }}</div>
            </div>
            <div style="font-size:0.78rem;color:#f59e0b;margin-top:0.5rem;">{{ $reasons[$reason] ?? 'Something about this payment did not add up. Check it in the Paystack dashboard.' }}</div>
            <form method="POST" action="{{ route('operator.payments.review', $payment) }}" style="display:flex;gap:0.5rem;margin-top:0.7rem;flex-wrap:wrap;">
                @csrf
                <label for="review-note-{{ $payment->id }}" style="position:absolute;left:-9999px;">What you did about this payment</label>
                <input id="review-note-{{ $payment->id }}" type="text" name="note" required maxlength="500" placeholder="What you did (refunded in dashboard, buyer contacted...)" class="dot-input" style="flex:1;min-width:260px;">
                <button type="submit" class="dot-btn dot-btn-ghost">Close review</button>
            </form>
        </div>
        @empty
        <div style="padding:1.6rem;text-align:center;font-size:0.82rem;color:#71717a;">No payments waiting for review.</div>
        @endforelse
    </div>

    {{-- Refunds --}}
    <h2 style="margin:0 0 0.8rem;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Refunds owed to buyers</h2>
    <div class="dot-card" style="overflow:hidden;margin-bottom:0.8rem;">
        @forelse($refundsToAction as $refund)
        <div style="padding:1rem 1.25rem;{{ ! $loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div style="font-size:0.85rem;font-weight:700;color:#f4f4f5;">
                    {{ $refund->order ? 'Order '.$refund->order->reference() : 'Order removed' }} · {{ $money($refund->amount_cents, $refund->currency) }}
                </div>
                <div style="font-size:0.7rem;color:#71717a;">{{ $refund->payment?->reference }}</div>
            </div>
            <div style="font-size:0.78rem;color:#f59e0b;margin-top:0.5rem;">
                Refund required: {{ $refund->status === RefundStatus::Failed ? 'Paystack reported this refund failed.' : 'Paystack has not accepted this refund yet.' }}
                Retry it here, or do it in the Paystack dashboard and mark it done. The buyer has not been refunded.
            </div>
            @if($refund->last_error)<div style="font-size:0.7rem;color:#71717a;margin-top:0.25rem;">Last error: {{ $refund->last_error }}</div>@endif
            <div style="display:flex;gap:0.6rem;margin-top:0.7rem;flex-wrap:wrap;align-items:center;">
                <form method="POST" action="{{ route('operator.refunds.retry', $refund) }}">
                    @csrf
                    <button type="submit" class="dot-btn dot-btn-primary">Retry with Paystack</button>
                </form>
                <form method="POST" action="{{ route('operator.refunds.mark-done', $refund) }}" style="display:flex;gap:0.5rem;flex:1;min-width:280px;">
                    @csrf
                    <label for="done-note-{{ $refund->id }}" style="position:absolute;left:-9999px;">Where you refunded it</label>
                    <input id="done-note-{{ $refund->id }}" type="text" name="note" required maxlength="500" placeholder="Refunded by hand: Paystack dashboard ref..." class="dot-input">
                    <button type="submit" class="dot-btn dot-btn-ghost">Mark refunded</button>
                </form>
            </div>
        </div>
        @empty
        <div style="padding:1.6rem;text-align:center;font-size:0.82rem;color:#71717a;">No refunds are waiting on you.</div>
        @endforelse
    </div>
    @if($refundsInFlight->isNotEmpty())
    <div style="font-size:0.75rem;color:#a1a1aa;margin-bottom:2rem;">
        With Paystack, not yet confirmed:
        @foreach($refundsInFlight as $refund){{ $refund->order?->reference() }} ({{ $money($refund->amount_cents, $refund->currency) }}){{ ! $loop->last ? ', ' : '' }}@endforeach.
        These are requests, not refunds, until Paystack reports them processed.
    </div>
    @else
    <div style="margin-bottom:2rem;"></div>
    @endif

    {{-- Unmatched events --}}
    <h2 style="margin:0 0 0.8rem;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Unmatched Paystack events</h2>
    <div class="dot-card" style="overflow:hidden;">
        @if($unmatched->isEmpty())
        <div style="padding:1.6rem;text-align:center;font-size:0.82rem;color:#71717a;">None recorded.</div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px solid rgba(67,70,86,0.3);">
                    @foreach(['When', 'Event', 'Reference', 'Amount', 'Why'] as $heading)<th style="{{ $th }}">{{ $heading }}</th>@endforeach
                </tr></thead>
                <tbody>
                    @foreach($unmatched as $event)
                    <tr style="border-bottom:1px solid rgba(67,70,86,0.15);">
                        <td style="{{ $td }}white-space:nowrap;">{{ $event->created_at->format('d M, H:i') }}</td>
                        <td style="{{ $td }}">{{ $event->event }}</td>
                        <td style="{{ $td }}font-family:'JetBrains Mono',monospace;font-size:0.7rem;">{{ $event->reference ?? '—' }}</td>
                        <td style="{{ $td }}white-space:nowrap;">{{ $event->amount_cents !== null ? $money($event->amount_cents, $event->currency) : '—' }}</td>
                        <td style="{{ $td }}">{{ $reasons[$event->reason] ?? ($event->reason ?? '—') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>
</x-app-layout>
