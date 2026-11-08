{{--
  The home page, for everyone (Discovery\HomeController). Every block states where it comes from and is left out when it has nothing real to show. Layout families change from block to
  block on purpose: an editorial lead, a shelf of category tiles with real counts, a scrolling strip, a ranked list, collection panels, a store spotlight.
  Variables: $sections (HomeSection by key), $region, $shelf, $feature, $storeModels, $signedIn.
--}}
@php
    $s = $sections;
    $storeUrl = function ($row) use ($storeModels) {
        $model = $storeModels->get($row['id']);

        return $model && \Illuminate\Support\Facades\Route::has('stores.show') ? route('stores.show', $model) : route('search', ['store' => [$row['id']]]);
    };
    $logo = fn ($row) => ! empty($row['logo_path']) ? \Illuminate\Support\Facades\Storage::disk('public')->url($row['logo_path']) : null;
    $popular = $s->get('searches');
    $yours = collect(['continue', 'interests', 'reorder'])->map(fn ($k) => $s->get($k))->filter();
@endphp
<x-store-layout :contained="false">
    <x-slot:title>Shop with the total in sight</x-slot:title>
    <x-slot:description>Dot.Emall is a marketplace for South African sellers. Search, browse by category or describe what you need; every product shows its price, delivery and total on one label before you pay.</x-slot:description>
    <x-slot:location><x-disc-region-chip /></x-slot:location>

    {{-- Lead: the headline against a real product and its label, with the reason it was chosen. --}}
    <section class="ds-hero" aria-labelledby="hero-h">
        <div class="ds-container ds-hero__grid">
            <div class="ds-hero__text">
                <h1 id="hero-h">Shop with the total in sight</h1>
                <p>Search South African sellers, or tell Emall what you need. Every product shows its price, delivery and total.</p>
                <div class="ds-page-header__actions">
                    <x-ds.button :href="route('categories.index')" size="lg" iconRight="arrow-right">Browse categories</x-ds.button>
                    <x-ds.button :href="route('assistant')" variant="quiet" size="lg" icon="sparkle">Ask Emall</x-ds.button>
                </div>
            </div>
            @if ($feature)
                @php($fp = $feature['product'])
                <div class="ds-hero__feature">
                    <a class="ds-hero__photo" href="{{ route('products.show', $fp) }}">
                        <img src="{{ $fp->images->first()->url() }}" alt="{{ $fp->name }}" width="720" height="720" fetchpriority="high">
                    </a>
                    <x-ds.price-label class="ds-hero__label" :price-cents="\App\Support\Money::toCents($fp->price)" :from="$fp->store?->name" :title="$fp->name" />
                    <p class="disc-hero-basis">{{ $feature['basis'] }}</p>
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

    {{-- Intent: say what you need, or start from what other people searched for. --}}
    <section class="disc-ask-band" aria-labelledby="ask-h">
        <div class="ds-container disc-ask-band__grid">
            <div>
                <h2 id="ask-h">Not sure what to buy?</h2>
                <p class="disc-muted">Describe it in your own words. Emall reads the request, searches the real catalogue and shows the facts, kept apart from its own suggestion.</p>
            </div>
            <div>
                <form class="disc-ask" method="get" action="{{ route('assistant') }}">
                    <label class="ds-visually-hidden" for="home-ask">What do you need</label>
                    <input class="ds-input" id="home-ask" name="text" type="text" maxlength="400" placeholder="A reliable laptop for university under R15,000">
                    <x-ds.button type="submit" icon="sparkle">Ask Emall</x-ds.button>
                </form>
                @if ($popular)
                    <p class="disc-muted disc-ask-band__label" id="popular-label">Popular searches</p>
                    <ul class="disc-chips" aria-labelledby="popular-label">
                        @foreach (array_slice($popular->items, 0, 6) as $row)<li><a class="disc-chip" href="{{ route('search', ['q' => $row['query']]) }}">{{ $row['query'] }}</a></li>@endforeach
                    </ul>
                    <p class="disc-basis">{{ $popular->basis }}</p>
                @endif
            </div>
        </div>
    </section>

    <div class="ds-container disc-home">
        {{-- Returning shoppers: their own history, private to them. --}}
        @foreach ($yours as $section)
            <x-disc-rail :id="'home-'.$section->key" :title="$section->title" :basis="$section->basis" :items="$section->items" :eager="$loop->first ? 4 : 0"
                :link="$section->key === 'continue' ? route('recently-viewed.index') : null" link-label="See everything you viewed" />
        @endforeach

        {{-- Category shelf: tiles sized by how many products can be bought, each with a photograph of something in it. --}}
        @if ($shelf)
            <x-ds.section title="Shop by category" id="categories" lead="Largest first, by the number of products you can buy today.">
                <x-slot:actions><a href="{{ route('categories.index') }}">All categories</a></x-slot:actions>
                <ul class="disc-tiles">
                    @foreach ($shelf as $tile)
                        <li class="disc-tile @if ($loop->first && count($shelf) >= 5) disc-tile--lead @endif">
                            <a href="{{ route('categories.show', $tile['slug']) }}">
                                @if ($tile['image'])<img src="{{ $tile['image'] }}" alt="" width="480" height="480" loading="lazy" decoding="async">@else<span class="disc-tile__noimg"><x-ds.icon name="image" :size="32" /></span>@endif
                                <span class="disc-tile__label"><strong>{{ $tile['name'] }}</strong><span>{{ $tile['basis'] }}</span></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-ds.section>
        @endif

        {{-- New arrivals: a strip. --}}
        @if ($s->has('new_arrivals'))
            <x-disc-rail id="new-arrivals" :title="$s['new_arrivals']->title" :basis="$s['new_arrivals']->basis" :items="$s['new_arrivals']->items" :eager="! $yours->count() ? 4 : 0" :link="route('search', ['sort' => 'newest'])" link-label="See all new listings" />
        @endif

        {{-- Trending: a ranked list with the numbers it is ranked by. --}}
        @if ($s->has('trending'))
            @php($trend = $s['trending'])
            <x-ds.section title="{{ $trend->title }}" id="trending" :lead="$trend->basis">
                <ol class="disc-ranked">
                    @foreach (array_slice($trend->items, 0, 6) as $item)
                        @php($p = $item->product)
                        <li>
                            <span class="disc-ranked__n ds-code" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            @if ($p->images->isNotEmpty())<img src="{{ $p->images->first()->url() }}" alt="" width="96" height="96" loading="lazy" decoding="async">@else<span class="disc-ranked__noimg"></span>@endif
                            <div class="disc-ranked__text">
                                <a href="{{ route('products.show', $p) }}">{{ $p->name }}</a>
                                <span class="disc-muted">{{ $item->basis }}</span>
                            </div>
                            <x-ds.price :amount="$p->price" size="sm" />
                        </li>
                    @endforeach
                </ol>
            </x-ds.section>
        @endif

        {{-- Collections: editorial panels. --}}
        @if ($s->has('collections'))
            @php($cols = $s['collections'])
            <x-ds.section :title="$cols->title" id="collections" :lead="$cols->basis">
                <x-slot:actions><a href="{{ route('collections.index') }}">All collections</a></x-slot:actions>
                <div class="disc-collections">
                    @foreach ($cols->items as $row)
                        <x-disc-collection-panel :collection="$row['collection']" :preview="$row['preview']" :basis="$row['basis']" :large="$loop->first" />
                    @endforeach
                </div>
            </x-ds.section>
        @endif

        {{-- Stores: a spotlight on the first, the rest in a list. --}}
        @if ($s->has('stores'))
            @php($st = $s['stores'])
            <x-ds.section :title="$st->title" id="stores" :lead="$st->basis">
                <x-slot:actions><a href="{{ emall_route('stores.index') !== '#' ? emall_route('stores.index') : route('search') }}">All stores</a></x-slot:actions>
                <div class="disc-spot">
                    @php($lead = $st->items[0])
                    <article class="disc-spot__lead">
                        <x-ds.avatar :name="$lead['name']" :src="$logo($lead)" size="lg" />
                        <h3><a href="{{ $storeUrl($lead) }}">{{ $lead['name'] }}</a></h3>
                        <p class="disc-muted">{{ $lead['basis'] }}</p>
                        <x-ds.button :href="$storeUrl($lead)" variant="quiet" iconRight="arrow-right">See what they sell</x-ds.button>
                    </article>
                    <ul class="disc-spot__list">
                        @foreach (array_slice($st->items, 1) as $row)
                            <li><x-ds.avatar :name="$row['name']" :src="$logo($row)" /><div><a href="{{ $storeUrl($row) }}">{{ $row['name'] }}</a><span class="disc-muted">{{ $row['basis'] }}</span></div></li>
                        @endforeach
                    </ul>
                </div>
            </x-ds.section>
        @endif

        {{-- Local: only when a province is known. --}}
        @if ($s->has('local'))
            @php($local = $s['local'])
            <x-ds.section :title="$local->title" id="local" :lead="$local->basis">
                <ul class="disc-spot__list disc-spot__list--wide">
                    @foreach ($local->items as $row)
                        <li><x-ds.avatar :name="$row['name']" /><div><a href="{{ $storeUrl($row) }}">{{ $row['name'] }}</a><span class="disc-muted">{{ $row['basis'] }}</span></div></li>
                    @endforeach
                </ul>
            </x-ds.section>
        @elseif (! $region)
            <section class="disc-nearby" aria-labelledby="nearby-h">
                <h2 id="nearby-h">See sellers near you</h2>
                <p class="disc-muted">Choose your province and Emall shows the sellers in it first. We keep only the province, in this browser.</p>
                <x-ds.button variant="quiet" icon="pin" data-dialog-open="region-dialog">Choose your province</x-ds.button>
            </section>
        @endif

        {{-- Selling. --}}
        <section class="ds-sell" aria-labelledby="sell-h">
            <div>
                <h2 id="sell-h">Sell on Emall</h2>
                <p>Sign in with InfoDot, open your store and list your products. Each listing is reviewed before it appears in the shop, and you manage orders and returns from one desk.</p>
            </div>
            <div class="ds-page-header__actions">
                <x-ds.button :href="emall_route('sell.start') !== '#' ? emall_route('sell.start') : emall_route('seller.store.create')" icon="store">{{ $signedIn ? 'Open your store' : 'Start selling' }}</x-ds.button>
            </div>
        </section>
    </div>
</x-store-layout>
