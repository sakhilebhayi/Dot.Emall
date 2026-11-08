<x-operator-layout>
    <x-slot:title>Listings</x-slot:title>
    <x-slot:heading>Listings</x-slot:heading>
    <x-slot:lead>New listings wait here until you approve them. A rejection needs a reason, and the seller reads it.</x-slot:lead>
    <x-slot:actions><x-ds.button variant="quiet" :href="route('operator.flags.index')" icon="flag">Flagged by the rules</x-ds.button></x-slot:actions>

    @php
        $chip = fn (string $s, string $label) => ['label' => $label, 'href' => route('operator.products.index', array_filter(['status' => $s, 'q' => $filters['q'], 'category' => $filters['category']])), 'current' => $status === $s, 'count' => $s === 'all' ? $counts->sum() : ($counts[$s] ?? 0)];
    @endphp
    <x-op.chips label="Listing status" :items="[$chip('pending_approval', 'Awaiting approval'), $chip('approved', 'Approved'), $chip('rejected', 'Rejected'), $chip('all', 'All')]" />

    <form method="GET" action="{{ route('operator.products.index') }}" class="op-filters" role="search" aria-label="Filter listings">
        <input type="hidden" name="status" value="{{ $status }}">
        <x-ds.input label="Listing name" name="q" type="search" :value="$filters['q']" />
        <x-ds.select label="Category" name="category" :options="$categories->pluck('name', 'id')->all()" :selected="$filters['category']" placeholder="Every category" />
        <x-ds.button type="submit" variant="quiet">Filter</x-ds.button>
        @if ($filters['q'] || $filters['category'] || $filters['store'])<x-ds.button variant="link" :href="route('operator.products.index', ['status' => $status])">Clear filters</x-ds.button>@endif
    </form>

    @if ($products->isEmpty())
        <x-ds.empty-state title="{{ $status === 'pending_approval' ? 'No listing is waiting' : 'No listing matches' }}" icon="box">
            {{ $status === 'pending_approval' ? 'New listings from sellers appear here for approval.' : 'Change the status or the filters above.' }}
        </x-ds.empty-state>
    @else
        <ul class="op-items" aria-label="Listings">
            @foreach ($products as $product)
                @php($cover = $product->images->first())
                <li class="op-item">
                    <div class="op-listing">
                        @if ($cover)<img class="op-thumb" src="{{ $cover->url() }}" alt="" width="72" height="72" loading="lazy">@else<span class="op-thumb" aria-hidden="true"></span>@endif
                        <div>
                            <div class="op-item__head">
                                <a class="op-row-link" href="{{ route('operator.products.show', $product) }}">{{ $product->name }}</a>
                                <span class="op-num">{{ \App\Support\Shop::amount($product->price) }}</span>
                            </div>
                            <div class="op-item__meta">
                                <span>{{ $product->store->name }}</span>
                                <span>{{ $product->category?->name ?? 'No category' }}</span>
                                <span>Submitted <x-op.age :at="$product->created_at" /> ago</span>
                                <span>@if ($product->store->created_at->gt(now()->subDays(14)))New store, opened <x-op.age :at="$product->store->created_at" /> ago @endif</span>
                            </div>
                            @if ($product->status === 'rejected' && $product->rejected_reason)<p class="op-quote">{{ $product->rejected_reason }}<small>Shown to the seller</small></p>@endif
                        </div>
                        <div class="op-inline">
                            <x-ds.status kind="product" :state="$product->status" />
                            @if ($product->status === 'pending_approval')
                                <form method="POST" action="{{ route('operator.products.approve', $product) }}">@csrf<x-ds.button type="submit" size="sm">Approve</x-ds.button></form>
                                <x-ds.button variant="quiet" size="sm" :href="route('operator.products.show', $product)">Review in full</x-ds.button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <x-ds.pagination :paginator="$products" />
    @endif
</x-operator-layout>
