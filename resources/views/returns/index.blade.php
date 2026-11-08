<x-account-layout>
    <x-slot:title>Your returns</x-slot:title>
    <x-slot:heading>Your returns</x-slot:heading>
    <x-slot:lead>Ask for a return within {{ $windowDays }} days of delivery. Faulty, not as described or wrong items are returned at the seller's cost.</x-slot:lead>

    @if ($eligible->isNotEmpty())
        <x-ds.section title="You can still return" lead="Delivered items inside the {{ $windowDays }}-day window.">
            <ul class="co-plain">
                @foreach ($eligible as $item)
                    <li class="co-row"><span><strong>{{ $item->displayName() }}</strong> <span class="ds-muted">from order {{ $item->order->reference() }}</span></span>
                        <x-ds.button :href="route('orders.returns.create', [$item->order, $item])" size="sm" variant="quiet" icon="return">Start a return</x-ds.button></li>
                @endforeach
            </ul>
        </x-ds.section>
    @endif

    <x-ds.section title="Your return requests">
        @if ($returns->isEmpty())
            <x-ds.empty-state title="No returns" icon="return">You have not asked to return anything. To start one, open a delivered order and choose Return on the item.</x-ds.empty-state>
        @else
            <x-ds.table caption="Your return requests">
                <x-slot:head><tr><th scope="col">Item</th><th scope="col">Order</th><th scope="col">Asked</th><th scope="col">State</th><th scope="col"><span class="ds-visually-hidden">Open</span></th></tr></x-slot:head>
                @foreach ($returns as $r)
                    <tr>
                        <td data-label="Item">{{ $r->item?->displayName() }}</td>
                        <td data-label="Order"><span class="ds-code">{{ $r->order?->reference() }}</span></td>
                        <td data-label="Asked">{{ $r->created_at->format('j M Y') }}</td>
                        <td data-label="State"><x-ds.badge :tone="match ($r->status) { \App\Enums\ReturnStatus::Approved, \App\Enums\ReturnStatus::Refunded => 'pos', \App\Enums\ReturnStatus::Declined => 'crit', \App\Enums\ReturnStatus::Requested, \App\Enums\ReturnStatus::Escalated => 'cau', default => 'neutral' }">{{ $r->status->label() }}</x-ds.badge></td>
                        <td data-label=""><x-ds.button :href="route('account.returns.show', $r)" size="sm" variant="quiet">Open<span class="ds-visually-hidden"> return for {{ $r->item?->displayName() }}</span></x-ds.button></td>
                    </tr>
                @endforeach
            </x-ds.table>
            <x-ds.pagination :paginator="$returns" />
        @endif
    </x-ds.section>
</x-account-layout>
