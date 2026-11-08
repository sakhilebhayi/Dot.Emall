@php
    use App\Enums\RefundStatus;

    $money = fn (int $cents, ?string $currency = null) => \App\Support\Shop::money($cents, $currency ?? $gateway['currency']);
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

@endphp
<x-operator-layout>
    <x-slot:title>Payments</x-slot:title>
    <x-slot:heading>Payments</x-slot:heading>
    <x-slot:lead>{{ $stateLine }}</x-slot:lead>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('operator.payments.events')" icon="list">Payment events log</x-ds.button></x-slot:actions>

    @if (session('error'))<x-ds.alert tone="crit">{{ session('error') }}</x-ds.alert>@endif

    <div class="ds-stack">
        <section class="op-block" aria-labelledby="gateway-h">
            <h2 id="gateway-h">Gateway</h2>
            <x-ds.dl>
                <dt>State</dt>
                <dd>
                    @if (! $gateway['configured'])<x-ds.badge tone="cau" icon="alert-triangle">Not configured</x-ds.badge>
                    @elseif ($gateway['problem'])<x-ds.badge tone="crit" icon="x-octagon">Refused</x-ds.badge>
                    @elseif ($gateway['testMode'])<x-ds.badge tone="cau" icon="alert-triangle">TEST MODE</x-ds.badge>
                    @else<x-ds.badge tone="pos" icon="check-circle">Live</x-ds.badge>@endif
                </dd>
                <dt>Detail</dt><dd>{{ $gateway['problem'] ?? ($gateway['configured'] ? 'Charging in '.$gateway['currency'].'.' : 'Set PAYSTACK_SECRET_KEY (a test key) to enable card payments.') }}</dd>
            </x-ds.dl>
        </section>

        @if ($paidTwice->isNotEmpty())
            <x-ds.alert tone="crit" title="Paid more than once">
                These orders have more than one successful payment. Check each in the Paystack dashboard and refund the extra charge:
                @foreach ($paidTwice as $row)<a href="{{ route('operator.people.order', $row->order_id) }}">order {{ $row->order_id }}</a> ({{ $row->payments }} payments, {{ $money((int) $row->total_cents) }} in all){{ ! $loop->last ? ', ' : '.' }}@endforeach
            </x-ds.alert>
        @endif

        <section class="op-block" aria-labelledby="review-h">
            <h2 id="review-h">Needs review ({{ $reviews->count() }})</h2>
            <p class="op-muted">Money that did not fit an order.</p>
            @forelse ($reviews as $payment)
                @php $reason = $payment->events->sortByDesc('id')->pluck('reason')->filter()->first(); @endphp
                <article class="op-item" aria-label="Payment {{ $payment->reference }}">
                    <div class="op-item__head">
                        <strong>@if ($payment->order)<a class="op-row-link" href="{{ route('operator.people.order', $payment->order) }}">Order {{ $payment->order->reference() }}</a>@else Order removed @endif · <span class="op-num">{{ $money($payment->amount_cents, $payment->currency) }}</span></strong>
                        <span class="op-muted"><x-op.age :at="$payment->updated_at" /> ago</span>
                    </div>
                    <p class="op-code">{{ $payment->reference }}</p>
                    <p>{{ $reasons[$reason] ?? 'Something about this payment did not add up. Check it in the Paystack dashboard.' }}</p>
                    <x-op.decide :bag="'payment-'.$payment->id" :action="route('operator.payments.review', $payment)" summary="Close the review" tone="quiet" field="note" label="What you did about this payment" hint="Refunded in the dashboard, buyer contacted, and so on. Kept in the audit trail." />
                </article>
            @empty
                <p class="op-muted">No payments waiting for review.</p>
            @endforelse
        </section>

        <section class="op-block" aria-labelledby="refunds-h">
            <h2 id="refunds-h">Refunds owed to buyers ({{ $refundsToAction->count() }})</h2>
            @forelse ($refundsToAction as $refund)
                <article class="op-item" aria-label="Refund {{ $refund->id }}">
                    <div class="op-item__head">
                        <strong>{{ $refund->order ? 'Order '.$refund->order->reference() : 'Order removed' }} · <span class="op-num">{{ $money($refund->amount_cents, $refund->currency) }}</span></strong>
                        <span class="op-code">{{ $refund->payment?->reference }}</span>
                    </div>
                    <x-ds.alert tone="cau" title="Refund required">{{ $refund->status === RefundStatus::Failed ? 'Paystack reported this refund failed.' : 'Paystack has not accepted this refund yet.' }} Retry it here, or do it in the Paystack dashboard and mark it done. The buyer has not been refunded.@if ($refund->last_error) Last error: {{ $refund->last_error }}@endif</x-ds.alert>
                    <div class="op-decisions">
                        <form method="POST" action="{{ route('operator.refunds.retry', $refund) }}">@csrf<x-ds.button type="submit" size="sm">Retry with Paystack</x-ds.button></form>
                        <x-op.decide :bag="'refund-'.$refund->id" :action="route('operator.refunds.mark-done', $refund)" summary="Mark refunded by hand" tone="quiet" field="note" label="Where you refunded it" hint="The Paystack dashboard reference, so the books can be checked." />
                    </div>
                </article>
            @empty
                <p class="op-muted">No refunds are waiting on you.</p>
            @endforelse
            @if ($refundsInFlight->isNotEmpty())
                <p class="op-muted">With Paystack, not yet confirmed: @foreach ($refundsInFlight as $refund){{ $refund->order?->reference() }} ({{ $money($refund->amount_cents, $refund->currency) }}){{ ! $loop->last ? ', ' : '' }}@endforeach. These are requests, not refunds, until Paystack reports them processed.</p>
            @endif
        </section>

        <section class="op-block" aria-labelledby="unmatched-h">
            <h2 id="unmatched-h">Unmatched Paystack events</h2>
            @if ($unmatched->isEmpty())
                <p class="op-muted">None recorded.</p>
            @else
                <x-ds.table caption="Unmatched Paystack events">
                    <x-slot:head><tr><th scope="col">When</th><th scope="col">Event</th><th scope="col">Reference</th><th scope="col" class="is-num">Amount</th><th scope="col">Why</th></tr></x-slot:head>
                    @foreach ($unmatched as $event)
                        <tr>
                            <td data-label="When">{{ $event->created_at->format('d M, H:i') }}</td>
                            <td data-label="Event">{{ $event->event }}</td>
                            <td data-label="Reference"><span class="op-code">{{ $event->reference ?? 'None' }}</span></td>
                            <td data-label="Amount" class="is-num">{{ $event->amount_cents !== null ? $money($event->amount_cents, $event->currency) : 'None' }}</td>
                            <td data-label="Why">{{ $reasons[$event->reason] ?? ($event->reason ?? 'None') }}</td>
                        </tr>
                    @endforeach
                </x-ds.table>
            @endif
        </section>
    </div>
</x-operator-layout>
