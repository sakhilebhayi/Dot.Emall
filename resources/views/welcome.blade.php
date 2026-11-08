@php
    // Live rows from the catalogue. Everything here is a plain query that fails soft: with no products, or no database, the page shows its honest empty state.
    $latest = collect();
    $categoriesLive = collect();
    try {
        $latest = \App\Models\Product::query()->browsable()->with(['store', 'images'])->latest()->limit(10)->get();
        $categoriesLive = \App\Models\StoreCategory::query()->whereNull('parent_id')->orderBy('name')->get();
    } catch (\Throwable) {
    }
    $featured = $latest->first(fn ($p) => $p->images->isNotEmpty());
    $row = $latest->reject(fn ($p) => $featured && $p->is($featured))->values();
    $loggedIn = auth()->check();
@endphp
<x-store-layout :contained="false">
    <x-slot:title>Shop with the total in sight</x-slot:title>
    <x-slot:description>Dot.Emall is a marketplace for South African sellers. Every product shows its price, delivery and total on one label before you pay.</x-slot:description>

    {{-- Hero: one sentence, two actions, and the real product with its label. --}}
    <section class="ds-hero" aria-labelledby="hero-h">
        <div class="ds-container ds-hero__grid">
            <div class="ds-hero__text">
                <h1 id="hero-h">See the total before you pay</h1>
                <p>One label shows the price, the delivery and the total, for products from South African sellers.</p>
                <div class="ds-page-header__actions">
                    <x-ds.button :href="emall_route('products.index')" size="lg" iconRight="arrow-right">Browse the shop</x-ds.button>
                    @unless ($loggedIn)<x-ds.button :href="route('login')" variant="quiet" size="lg">Sign in with InfoDot</x-ds.button>
@endunless
                </div>
                @unless ($loggedIn)<p class="ds-hero__note">Accounts come from InfoDot. Sign in to buy, sell and follow your orders.</p>@endunless
            </div>
            @if ($featured)
                <div class="ds-hero__feature">
                    <a class="ds-hero__photo" href="{{ route('products.show', $featured) }}">
                        <img src="{{ $featured->images->first()->url() }}" alt="{{ $featured->name }}" width="720" height="720" fetchpriority="high">
                    </a>
                    <x-ds.price-label class="ds-hero__label" :price-cents="\App\Support\Money::toCents($featured->price)" :from="$featured->store->name" :title="$featured->name" />
                </div>
            @else
                <div class="ds-label ds-hero__label ds-hero__label--solo" role="group" aria-label="How a price label reads">
                    <div class="ds-label__main"><span class="ds-label__from">Every product has one</span><span class="ds-label__from" style="color: var(--ink); font-weight: 650;">The price label</span><p style="font-size: var(--text-md);">The seller's price, in rand</p></div>
                    <div class="ds-label__perf" aria-hidden="true"></div>
                    <dl class="ds-label__rows">
                        <div class="ds-label__row"><dt>Delivery</dt><dd><small>Worked out for your address</small></dd></div>
                        <div class="ds-label__row ds-label__row--total"><dt>Total</dt><dd><small>Price plus delivery</small></dd></div>
                    </dl>
                </div>
            @endif
        </div>
    </section>

    <div class="ds-container">
        {{-- Latest listings --}}
        <x-ds.section title="Latest from the stores" id="latest" lead="Approved listings, newest first.">
            @if ($row->isNotEmpty())
                <x-slot:actions><a href="{{ emall_route('products.index') }}">See all products</a></x-slot:actions>
                <div class="ds-strip-row" role="list">
                    @foreach ($row as $p)
                        <div role="listitem">
                            <x-ds.product-card :href="route('products.show', $p)" :title="$p->name" :image="$p->images->first()?->url()" :store="$p->store->name" :amount="$p->price"
                                :rating-value="$p->rating_average ?? 0" :rating-count="$p->rating_count ?? 0" :stock="$p->stock" :eager="$loop->first" />
                        </div>
                    @endforeach
                </div>
            @else
                <x-ds.empty-state title="No products listed yet" icon="store">Sellers sign in with InfoDot, open a store and list products. Each listing is reviewed before it appears here.
                    <x-slot:actions><x-ds.button :href="route('login')">Sign in with InfoDot</x-ds.button></x-slot:actions>
                </x-ds.empty-state>
            @endif
        </x-ds.section>

        @if ($categoriesLive->isNotEmpty())
            <x-ds.section title="Shop by category" id="categories">
                <ul class="ds-catlist">
                    @foreach ($categoriesLive as $c)
                        <li><a href="{{ route('products.index', ['category' => $c->id]) }}">{{ $c->name }}<x-ds.icon name="arrow-right" :size="18" /></a></li>
                    @endforeach
                </ul>
            </x-ds.section>
        @endif

        {{-- How buying works --}}
        <x-ds.section title="How buying works" id="buying">
            <ol class="ds-howto">
                <li><span class="ds-howto__n ds-code" aria-hidden="true">01</span><div><h3>Find it</h3><p>Search by name, or browse by category. Product photos come from the seller, and a rating shows only once there is a review behind it.</p></div></li>
                <li><span class="ds-howto__n ds-code" aria-hidden="true">02</span><div><h3>Read the label</h3><p>The label shows the price, what delivery costs to your address, and the total. If a figure is not known yet, the label says so instead of guessing.</p></div></li>
                <li><span class="ds-howto__n ds-code" aria-hidden="true">03</span><div><h3>Follow the parcel</h3><p>Each order has a waybill: paid, packing, shipped, delivered. A step with no date means it has not happened yet. Tracking details come from the seller.</p></div></li>
            </ol>
        </x-ds.section>

        {{-- Selling --}}
        <section class="ds-sell" aria-labelledby="sell-h">
            <div>
                <h2 id="sell-h">Selling on Emall</h2>
                <p>Sign in with InfoDot, open your store and list your products. Each listing is reviewed by the platform before it appears in the shop, and you manage orders from one desk.</p>
            </div>
            <div class="ds-page-header__actions">
                <x-ds.button :href="$loggedIn ? emall_route('seller.store.create') : route('login')" icon="store">{{ $loggedIn ? 'Open your store' : 'Sign in to open a store' }}</x-ds.button>
            </div>
        </section>
    </div>
</x-store-layout>
