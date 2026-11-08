<x-operator-layout>
    <x-slot:title>Returns</x-slot:title>
    <x-slot:heading>Returns</x-slot:heading>
    <x-slot:lead>Returns that reached you: the seller did not answer in time, or the buyer disputed a decline. Your decision and your reason go to both sides.</x-slot:lead>

    <x-op.chips label="Returns" :items="[
        ['label' => 'Waiting for you', 'href' => route('operator.returns.index'), 'current' => $tab === 'waiting', 'count' => $waiting],
        ['label' => 'Settled by an operator', 'href' => route('operator.returns.index', ['tab' => 'settled']), 'current' => $tab === 'settled'],
    ]" />

    @if ($returns->isEmpty())
        <x-ds.empty-state title="{{ $tab === 'waiting' ? 'No return is waiting for you' : 'Nothing settled yet' }}" icon="return">{{ $tab === 'waiting' ? 'A return arrives here when a seller leaves it unanswered past the deadline, or when a buyer disputes a decline.' : 'Returns you settle are listed here with your reason.' }}</x-ds.empty-state>
    @else
        <x-ds.table caption="Returns">
            <x-slot:head><tr><th scope="col">Return</th><th scope="col">Item</th><th scope="col">Seller</th><th scope="col">Why it is here</th><th scope="col" class="is-num">Refund</th><th scope="col">{{ $tab === 'waiting' ? 'Waiting' : 'Status' }}</th></tr></x-slot:head>
            @foreach ($returns as $r)
                <tr>
                    <td data-label="Return"><a class="op-row-link" href="{{ route('operator.returns.show', $r) }}">#{{ $r->id }}</a></td>
                    <td data-label="Item">{{ $r->item?->displayName() ?? 'Item' }}</td>
                    <td data-label="Seller">{{ $r->seller?->name ?? 'Unknown' }}</td>
                    <td data-label="Why it is here">{{ $r->escalation_reason === 'buyer_disputed_decline' ? 'Buyer disputed a decline' : ($r->escalation_reason ? str_replace('_', ' ', ucfirst($r->escalation_reason)) : 'Seller did not answer') }}</td>
                    <td data-label="Refund" class="is-num">{{ \App\Support\Shop::money((int) $r->refund_cents, $r->order?->currency) }}</td>
                    <td data-label="{{ $tab === 'waiting' ? 'Waiting' : 'Status' }}">@if ($tab === 'waiting')<x-op.age :at="$r->escalated_at" />@else{{ $r->status->label() }}@endif</td>
                </tr>
            @endforeach
        </x-ds.table>
        <x-ds.pagination :paginator="$returns" />
    @endif
</x-operator-layout>
