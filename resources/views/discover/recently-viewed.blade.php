{{-- Recently viewed: this browser's or this account's own list. Variables: $items (RecommendedItem list), $signedIn. --}}
<x-store-layout>
    <x-slot:title>Recently viewed</x-slot:title>
    <x-slot:description>The products you looked at lately. Only you can see this list.</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Recently viewed']]" /></x-slot:breadcrumbs>
    <x-slot:heading>Recently viewed</x-slot:heading>
    <x-slot:lead>The last {{ config('discovery.views.keep_per_person', 20) }} products you opened that can still be bought, newest first. {{ $signedIn ? 'It follows your account.' : 'It is kept for this browser, and moves to your account if you sign in.' }}</x-slot:lead>

    @if ($items)
        <ul class="disc-results disc-results--grid disc-results--bare">
            @foreach ($items as $item)<li><x-disc-card :product="$item->product" :eager="$loop->index < 4" /></li>@endforeach
        </ul>
        <form method="POST" action="{{ route('recently-viewed.clear') }}" class="disc-save">
            @csrf @method('DELETE')
            <x-ds.button type="submit" variant="quiet" icon="trash">Clear this list</x-ds.button>
            <p class="disc-muted">This removes the list only. Orders and wishlists are not affected.</p>
        </form>
    @else
        <x-ds.empty-state title="Nothing viewed yet" icon="eye">Products you open appear here so you can find them again.
            <x-slot:actions><x-ds.button :href="route('products.index')">Browse products</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @endif
</x-store-layout>
