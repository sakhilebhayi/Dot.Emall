@php
    use App\Support\Shop;
    $statusLinks = [
        null => ['All', $total],
        'approved' => ['Live', $counts['approved'] ?? 0],
        'pending_approval' => ['In review', $counts['pending_approval'] ?? 0],
        'rejected' => ['Rejected', $counts['rejected'] ?? 0],
        'off' => ['Switched off', null],
    ];
    $current = $filters['status'] ?? null;
    $qs = fn (?string $status) => array_filter(['status' => $status, 'q' => $filters['q'] ?? null, 'stock' => $filters['stock'] ?? null, 'category' => $filters['category'] ?? null]);
@endphp
<x-seller-layout>
    <x-slot:title>Products</x-slot:title>
    <x-slot:heading>Products</x-slot:heading>
    <x-slot:lead>Your listings, what is in stock and what the Emall team has decided about each.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button variant="primary" :href="route('seller.products.create')" icon="plus">New product</x-ds.button>
    </x-slot:actions>

    <div class="sd-page">
        <x-seller.errors />

        <nav class="sd-tabnav" aria-label="Listing status">
            @foreach ($statusLinks as $key => [$label, $n])
                <a href="{{ route('seller.products.index', $qs($key)) }}" @if ((string) $current === (string) $key) aria-current="page" @endif>{{ $label }}@if ($n !== null)<span class="sd-tabnav__zero">{{ $n }}</span>@endif</a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('seller.products.index') }}" class="sd-filters" role="search" aria-label="Filter products">
            @if ($current)<input type="hidden" name="status" value="{{ $current }}">@endif
            <x-ds.input label="Search name or SKU" name="q" type="search" :value="$filters['q'] ?? ''" maxlength="100" />
            <x-ds.select label="Stock" name="stock" :options="['in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock']" :selected="$filters['stock'] ?? ''" placeholder="Any stock level" />
            <x-ds.select label="Category" name="category" :options="$categories" :selected="$filters['category'] ?? ''" placeholder="Any category" />
            <span></span>
            <x-ds.button type="submit" variant="quiet" icon="search">Filter</x-ds.button>
        </form>

        @if ($products->isEmpty())
            @if ($total === 0)
                <x-ds.empty-state title="No products yet" icon="box">Add your first product with a photo, a price and how many you have. The Emall team checks it before it appears to shoppers.
                    <x-slot:actions><x-ds.button variant="primary" :href="route('seller.products.create')" icon="plus">Add a product</x-ds.button></x-slot:actions>
                </x-ds.empty-state>
            @else
                <x-ds.empty-state title="Nothing matches those filters" icon="search">Try a shorter search, or clear the filters.
                    <x-slot:actions><x-ds.button variant="quiet" :href="route('seller.products.index')">Clear filters</x-ds.button></x-slot:actions>
                </x-ds.empty-state>
            @endif
        @else
            <form method="POST" action="{{ route('seller.products.bulk') }}" aria-label="Change several listings">
                @csrf
                @foreach (['q', 'status', 'stock', 'category', 'page'] as $keep)@if (request()->filled($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif @endforeach
                <div class="sd-bulk">
                    <label class="ds-choice" style="min-height: var(--tap);"><input type="checkbox" data-select-all="ids[]"><span class="ds-choice__text"><span class="ds-choice__label">Tick all on this page</span></span></label>
                    <button type="submit" name="action" value="activate" class="ds-btn ds-btn--quiet ds-btn--sm" data-bulk-action disabled>Switch on</button>
                    <button type="submit" name="action" value="deactivate" class="ds-btn ds-btn--quiet ds-btn--sm" data-bulk-action disabled>Switch off</button>
                    <span class="sd-quiet" data-bulk-count aria-live="polite">Tick listings to change them together.</span>
                </div>
                <ul class="sd-list">
                    @foreach ($products as $p)
                        @php $cover = $p->images->first(); $multi = $p->active_variants_count > 1; @endphp
                        <li class="sd-item">
                            <span class="sd-select-cell"><input type="checkbox" name="ids[]" value="{{ $p->id }}" aria-label="Select {{ $p->name }}"></span>
                            <div class="sd-item__main">
                                <div class="sd-cluster">
                                    <span class="sd-thumb">@if ($cover)<img src="{{ $cover->url() }}" alt="" width="56" height="56" loading="{{ $loop->index < 8 ? 'eager' : 'lazy' }}" decoding="async">@else<x-ds.icon name="image" :size="24" />@endif</span>
                                    <a class="sd-item__title" href="{{ route('seller.products.edit', $p) }}">{{ $p->name }}</a>
                                </div>
                                <div class="sd-item__meta">
                                    <span class="sd-num"><strong>{{ $multi ? 'From ' : '' }}{{ Shop::amount($p->price) }}</strong></span>
                                    @if ($p->stock <= 0)<x-ds.status kind="product" state="out_of_stock" />
                                    @elseif ($p->stock <= $p->low_stock_threshold)<x-ds.status kind="product" state="low_stock" :quantity="$p->stock" />
                                    @else<span>{{ $p->stock }} in stock</span>@endif
                                    @if ($multi)<span>{{ $p->active_variants_count }} options</span>@endif
                                    @if ($p->category)<span>{{ $p->category->name }}</span>@endif
                                    @if ($p->sku)<span class="sd-code">{{ $p->sku }}</span>@endif
                                </div>
                                @if ($p->status === 'rejected' && $p->rejected_reason)<p class="sd-quiet" style="color: var(--crit);">Rejected: {{ $p->rejected_reason }}</p>@endif
                            </div>
                            <div class="sd-item__side">
                                @if ($p->status === 'pending_approval')<x-ds.status kind="product" state="pending_review" />
                                @elseif ($p->status === 'rejected')<x-ds.status kind="product" state="rejected" />@endif
                                @unless ($p->is_active)<x-ds.status kind="product" state="unlisted" />@endunless
                                <x-ds.button variant="quiet" size="sm" :href="route('seller.products.edit', $p)" icon="edit">Edit</x-ds.button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </form>
            <x-ds.pagination :paginator="$products" />
        @endif
    </div>
</x-seller-layout>
