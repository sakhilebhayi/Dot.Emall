<x-operator-layout>
    <x-slot:title>Payment events</x-slot:title>
    <x-slot:heading>Payment events</x-slot:heading>
    <x-slot:lead>Every notice Paystack sent us (by webhook or when a buyer came back from paying) and what we did with it. A webhook with a bad signature is refused before it is recorded, so it is not listed.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Payments', 'href' => route('operator.payments.index')], ['label' => 'Events']]" /></x-slot:breadcrumbs>

    <x-op.chips label="Outcome" :items="array_merge([['label' => 'All', 'href' => route('operator.payments.events'), 'current' => $outcome === null, 'count' => $counts->sum()]], collect(['applied' => 'Applied', 'duplicate' => 'Duplicate', 'unmatched' => 'Unmatched', 'needs_review' => 'Needs review'])->map(fn ($l, $k) => ['label' => $l, 'href' => route('operator.payments.events', ['outcome' => $k]), 'current' => $outcome === $k, 'count' => $counts[$k] ?? 0])->values()->all())" />

    @if ($events->isEmpty())
        <x-ds.empty-state title="No events" icon="card">Paystack has not told us about any payment yet.</x-ds.empty-state>
    @else
        <x-ds.table caption="Payment events">
            <x-slot:head><tr><th scope="col">When</th><th scope="col">Source</th><th scope="col">Outcome</th><th scope="col">Reference</th><th scope="col" class="is-num">Amount</th><th scope="col">Order</th><th scope="col">Why</th></tr></x-slot:head>
            @foreach ($events as $e)
                <tr>
                    <td data-label="When">{{ $e->created_at->format('d M, H:i') }}</td>
                    <td data-label="Source">{{ $e->source }}</td>
                    <td data-label="Outcome"><x-ds.badge :tone="['applied' => 'pos', 'duplicate' => 'neutral', 'unmatched' => 'crit', 'needs_review' => 'cau'][$e->outcome] ?? 'neutral'" :icon="['applied' => 'check-circle', 'duplicate' => 'copy', 'unmatched' => 'x-octagon', 'needs_review' => 'alert-triangle'][$e->outcome] ?? 'info'">{{ str_replace('_', ' ', ucfirst($e->outcome)) }}</x-ds.badge></td>
                    <td data-label="Reference"><span class="op-code">{{ $e->reference ?? 'None' }}</span></td>
                    <td data-label="Amount" class="is-num">{{ $e->amount_cents !== null ? \App\Support\Shop::money($e->amount_cents, $e->currency) : 'None' }}</td>
                    <td data-label="Order">@if ($e->order_id)<a href="{{ route('operator.people.order', $e->order_id) }}">#{{ $e->order_id }}</a>@else None @endif</td>
                    <td data-label="Why">{{ $e->reason ? str_replace('_', ' ', $e->reason) : 'None' }}</td>
                </tr>
            @endforeach
        </x-ds.table>
        <x-ds.pagination :paginator="$events" />
    @endif
</x-operator-layout>
