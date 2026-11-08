@php use App\Enums\RefundStatus; use App\Support\Shop; @endphp
<x-account-layout>
    <x-slot:title>Return for {{ $item?->displayName() }}</x-slot:title>
    <x-slot:heading>Return: {{ $item?->displayName() }}</x-slot:heading>
    <x-slot:lead>From order {{ $order->reference() }}. Asked on {{ $return->created_at->format('j M Y') }}.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Returns', 'href' => route('account.returns')], ['label' => $order->reference(), 'href' => route('orders.show', $order)], ['label' => 'Return']]" /></x-slot:breadcrumbs>

    @include('returns._state', ['return' => $return, 'respondDays' => $respondDays])

    <div class="co-layout co-layout--order">
        <div class="co-main">
            <x-ds.panel title="Your request">
                <dl class="ds-dl">
                    <dt>Reason</dt><dd>{{ $return->reason_category->label() }} <span class="ds-muted">({{ $return->reason_category->sellerPaysReturn() ? 'the seller pays for the return' : 'you pay for the return' }})</span></dd>
                    @if ($return->note)<dt>What you wrote</dt><dd>{{ $return->note }}</dd>@endif
                    <dt>Refund if approved</dt><dd>{{ $order->money((int) $return->refund_cents) }} <span class="ds-muted">(the price you paid for the item after any promo share; not delivery)</span></dd>
                    @if ($return->delivered_at)<dt>Delivered</dt><dd>{{ $return->delivered_at->format('j M Y') }}</dd>@endif
                </dl>
                @if (count($return->photo_paths ?? []) > 0)
                    <ul class="co-photos" aria-label="Your photos">
                        @foreach ($return->photo_paths as $i => $path)<li><a href="{{ route('return-photos.show', [$return, $i]) }}" target="_blank" rel="noopener"><img src="{{ route('return-photos.show', [$return, $i]) }}" alt="Your photo {{ $i + 1 }}" loading="lazy" width="120" height="120"></a></li>@endforeach
                    </ul>
                @endif
            </x-ds.panel>

            @if ($return->seller_response && $return->status !== \App\Enums\ReturnStatus::Requested)
                <x-ds.panel title="The seller's answer"><p>{{ $return->seller_response }}</p><p class="ds-muted">Answered {{ $return->responded_at?->format('j M Y') }}.</p></x-ds.panel>
            @endif
            @if ($return->resolution_note)
                <x-ds.panel title="The Emall team's decision"><p>{{ $return->resolution_note }}</p></x-ds.panel>
            @endif

            @if ($canEscalate)
                <x-ds.panel title="Disagree with the decline?">
                    <form method="POST" action="{{ route('account.returns.escalate', $return) }}">
                        @csrf
                        <x-ds.textarea label="Tell the Emall team why (optional)" name="note" id="esc-note" rows="3" hint="They read your request, the seller's reason and this note, then decide." />
                        <x-ds.button type="submit" icon="flag">Send to the Emall team</x-ds.button>
                    </form>
                </x-ds.panel>
            @endif
        </div>

        <aside class="co-side">
            <x-ds.panel title="Refund">
                @if ($return->refund)
                    @php $refund = $return->refund; @endphp
                    <p><strong>{{ Shop::money($refund->amount_cents, $refund->currency) }}</strong>:
                        @switch($refund->status)
                            @case(RefundStatus::Refunded)refunded{{ $refund->processed_at ? ' on '.$refund->processed_at->format('j M Y') : '' }}. It can take several business days to reach your account.@break
                            @case(RefundStatus::Submitted)asked of Paystack. It is not complete until Paystack confirms.@break
                            @case(RefundStatus::Failed)did not go through. The Emall team has it and will retry.@break
                            @default recorded and being arranged. It has not been sent yet.
                        @endswitch
                    </p>
                @else
                    <p class="ds-muted">No refund is recorded yet. One is recorded when the seller confirms the item is back.</p>
                @endif
            </x-ds.panel>
            <p><x-ds.button :href="route('orders.show', $order)" variant="quiet" icon="receipt">Back to the order</x-ds.button></p>
        </aside>
    </div>
</x-account-layout>
