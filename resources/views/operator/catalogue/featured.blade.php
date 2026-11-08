<x-operator-layout>
    <x-slot:title>Featured stores</x-slot:title>
    <x-slot:heading>Featured stores</x-slot:heading>
    <x-slot:lead>Your selection of stores to feature, up to {{ $limit }}. It is labelled as the editors' pick wherever it appears, and it is never for sale. A store that is suspended drops out of the shop's list without being removed from yours.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Merchandising', 'href' => route('operator.merch.index')], ['label' => 'Featured stores']]" /></x-slot:breadcrumbs>

    @php($bag = $errors->getBag('featured'))
    @if ($bag->any())<x-ds.alert tone="crit">{{ $bag->first() }}</x-ds.alert>@endif

    <div class="op-split">
        <section class="op-block" aria-labelledby="list-h">
            <h2 id="list-h">Featured now ({{ $featured->count() }} of {{ $limit }})</h2>
            @if ($featured->isEmpty())
                <x-ds.empty-state title="No store is featured" icon="store">The homepage then shows only stores that have earned a place by completed orders.</x-ds.empty-state>
            @else
                <x-ds.table caption="Featured stores">
                    <x-slot:head><tr><th scope="col">Store</th><th scope="col">Shown to shoppers</th><th scope="col">Note</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
                    @foreach ($featured as $f)
                        <tr>
                            <td data-label="Store"><a class="op-row-link" href="{{ route('operator.stores.show', $f->store_id) }}">{{ $f->store?->name }}</a></td>
                            <td data-label="Shown to shoppers">{{ $f->store?->is_active ? 'Yes' : 'No, the store is closed' }}</td>
                            <td data-label="Note">{{ $f->note ?: 'None' }}</td>
                            <td data-label="Actions"><form method="POST" action="{{ route('operator.catalogue.featured.remove', $f->store_id) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="quiet" size="sm" aria-label="Stop featuring {{ $f->store?->name }}">Remove</x-ds.button></form></td>
                        </tr>
                    @endforeach
                </x-ds.table>
            @endif
        </section>
        <aside class="op-side" aria-label="Feature a store">
            <section class="op-block">
                <h2>Feature a store</h2>
                <form method="POST" action="{{ route('operator.catalogue.featured.add') }}" class="ds-stack">
                    @csrf
                    <x-ds.select label="Store" name="store_id" id="feat-store" :options="$candidates->pluck('name', 'id')->all()" placeholder="Choose an open store" required />
                    <x-ds.input label="Why (optional)" name="note" id="feat-note" maxlength="190" hint="For you and the next operator. Not shown to shoppers." />
                    <x-ds.button type="submit">Feature this store</x-ds.button>
                </form>
            </section>
        </aside>
    </div>
</x-operator-layout>
