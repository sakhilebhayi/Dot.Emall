<x-store-layout>
    <x-slot:title>Collections</x-slot:title>
    <x-slot:description>Hand-picked and rule-based groups of products from the Dot.Emall team, with the dates they run.</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Collections']]" /></x-slot:breadcrumbs>
    <x-slot:heading>Collections</x-slot:heading>
    <x-slot:lead>Groups made by the marketplace team. Only products you can buy now are shown, and each collection says when it runs.</x-slot:lead>

    @if ($live->isNotEmpty())
        <div class="disc-collections">
            @foreach ($live as $row)
                <x-disc-collection-panel :collection="$row['collection']" :preview="$row['preview']" :large="$loop->first" :basis="$row['collection']->type === 'manual' ? 'Picked by our team' : 'Always up to date with its rule'" />
            @endforeach
        </div>
    @else
        <x-ds.empty-state title="No collections right now" icon="tag">Collections are added by the marketplace team and show here while they run.
            <x-slot:actions><x-ds.button :href="route('products.index')" variant="quiet">See all products</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @endif
</x-store-layout>
