<x-operator-layout>
    <x-slot:title>Stores</x-slot:title>
    <x-slot:heading>Stores</x-slot:heading>
    <x-slot:lead>Stores waiting to open, and every other store by status. Approving, sending back and suspending all carry a reason the seller reads.</x-slot:lead>

    @php
        $chip = fn (string $s, string $label) => ['label' => $label, 'href' => route('operator.stores.index', array_filter(['status' => $s, 'q' => $q])), 'current' => $status === $s, 'count' => $s === 'all' ? $counts->sum() : ($counts[$s] ?? 0)];
    @endphp
    <x-op.chips label="Store status" :items="[$chip('pending_review', 'Awaiting review'), $chip('active', 'Active'), $chip('suspended', 'Suspended'), $chip('draft', 'Draft'), $chip('all', 'All')]" />

    <form method="GET" action="{{ route('operator.stores.index') }}" class="op-filters" role="search" aria-label="Find a store">
        <input type="hidden" name="status" value="{{ $status }}">
        <x-ds.input label="Store name" name="q" type="search" :value="$q" />
        <x-ds.button type="submit" variant="quiet">Find</x-ds.button>
    </form>

    @if ($stores->isEmpty())
        <x-ds.empty-state title="{{ $status === 'pending_review' ? 'No store is waiting' : 'No store matches' }}" icon="store">{{ $status === 'pending_review' ? 'Stores appear here when a seller submits one for review. Today new stores open straight away unless review is switched on (EMALL_SELLER_REQUIRES_REVIEW).' : 'Change the status or the name above.' }}</x-ds.empty-state>
    @else
        <x-ds.table caption="Stores">
            <x-slot:head><tr><th scope="col">Store</th><th scope="col">Owner</th><th scope="col">Status</th><th scope="col" class="is-num">Listings</th><th scope="col">{{ $status === 'pending_review' ? 'Waiting' : 'Opened' }}</th></tr></x-slot:head>
            @foreach ($stores as $store)
                <tr>
                    <td data-label="Store"><a class="op-row-link" href="{{ route('operator.stores.show', $store) }}">{{ $store->name }}</a></td>
                    <td data-label="Owner">{{ $store->owner?->name }}</td>
                    <td data-label="Status"><x-ds.badge :tone="['active' => 'pos', 'pending_review' => 'cau', 'suspended' => 'crit', 'draft' => 'neutral'][$store->status->value] ?? 'neutral'" :icon="['active' => 'check-circle', 'pending_review' => 'hourglass', 'suspended' => 'ban', 'draft' => 'edit'][$store->status->value] ?? 'info'">{{ $store->status->label() }}</x-ds.badge></td>
                    <td data-label="Listings" class="is-num">{{ $store->products_count }}</td>
                    <td data-label="{{ $status === 'pending_review' ? 'Waiting' : 'Opened' }}"><x-op.age :at="$status === 'pending_review' ? $store->submitted_at : $store->created_at" /></td>
                </tr>
            @endforeach
        </x-ds.table>
        <x-ds.pagination :paginator="$stores" />
    @endif
</x-operator-layout>
