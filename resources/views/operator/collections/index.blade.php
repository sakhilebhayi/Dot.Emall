<x-operator-layout>
    <x-slot:title>Collections</x-slot:title>
    <x-slot:heading>Collections</x-slot:heading>
    <x-slot:lead>Editorial groups of listings. A collection is public only when it is published and inside its dates, and only buyable listings are ever shown.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Collections']]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.button :href="route('operator.collections.create')" icon="plus">New collection</x-ds.button></x-slot:actions>

    @if ($collections->isEmpty())
        <x-ds.empty-state title="No collections yet" icon="grid">Make one from a hand-picked list or a rule, preview what it matches, and publish it when it is ready.</x-ds.empty-state>
    @else
        <x-ds.table caption="Collections">
            <x-slot:head><tr><th scope="col">Collection</th><th scope="col">Kind</th><th scope="col">Dates</th><th scope="col">State</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
            @foreach ($collections as $c)
                <tr>
                    <td data-label="Collection"><a class="op-row-link" href="{{ route('operator.collections.edit', $c) }}">{{ $c->title }}</a></td>
                    <td data-label="Kind">{{ $c->type === 'dynamic' ? 'Rule' : 'Hand-picked' }}</td>
                    <td data-label="Dates">{{ $c->starts_at ? $c->starts_at->format('d M Y') : 'No start' }} to {{ $c->ends_at ? $c->ends_at->format('d M Y') : 'no end' }}</td>
                    <td data-label="State">
                        @if ($c->isLive())<x-ds.badge tone="pos" icon="check-circle">Live</x-ds.badge>
                        @elseif ($c->visibility === 'public')<x-ds.badge tone="cau" icon="clock">{{ $c->starts_at && $c->starts_at->isFuture() ? 'Scheduled' : 'Ended' }}</x-ds.badge>
                        @else<x-ds.badge icon="edit">Draft</x-ds.badge>@endif
                    </td>
                    <td data-label="Actions"><x-ds.button variant="quiet" size="sm" :href="route('operator.collections.edit', $c)">Edit</x-ds.button></td>
                </tr>
            @endforeach
        </x-ds.table>
    @endif
</x-operator-layout>
