{{-- One store (R-43 W4). Data: App\Http\Controllers\StorefrontController::show(). Public fields only: no owner, e-mail, phone, legal name or private figure.
     R-43 polish #11 (D-184): the products and their category chips come straight under the store's name; the trust ladder is summarised in one line with a "How this is measured"
     disclosure; delivery, returns, reviews and the contact form follow the products. --}}
@php
    $logo = $directory->logoUrl($store);
    $banner = $directory->bannerUrl($store);
    $place = collect([$store->city, $store->region])->filter()->implode(', ');
    $chips = $result->facets['categories'] ?? [];
    $enquiry = $errors->getBag('enquiry');
    $query = request()->except('page', 'category');
    $rungs = $directory->rungs($trust);
    $tally = collect($rungs)->countBy('state');
    $measured = collect($rungs)->where('state', 'metric')->filter(fn ($r) => ! str_starts_with((string) ($r['value'] ?? ''), 'Not enough') && ! str_starts_with((string) ($r['value'] ?? ''), 'No reviews'))->count();
    $description = \Illuminate\Support\Str::limit(trim(strip_tags((string) ($store->description ?: 'Products from '.$store->name.' on Dot.Emall.'))), 155);
@endphp
<x-store-layout :robots="$closed ? 'noindex' : null">
    <x-slot:title>{{ $store->name }}</x-slot:title>
    <x-slot:description>{{ $description }}</x-slot:description>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Home', 'href' => url('/')], ['label' => 'Stores', 'href' => route('stores.index')], ['label' => $store->name]]" /></x-slot:breadcrumbs>
    <x-slot:head>
        @unless ($closed)
            <link rel="canonical" href="{{ route('stores.show', $store) }}">
            <meta property="og:title" content="{{ $store->name }}">
            <meta property="og:description" content="{{ $description }}">
            @if ($logo)<meta property="og:image" content="{{ $logo }}">@endif
        @endunless
    </x-slot:head>

    @if ($closed)
        <x-ds.alert tone="cau" class="pdp-preview" title="This store is closed">Only its own people and the marketplace team can see this page.</x-ds.alert>
    @endif

    @if ($banner)<div class="sh-banner"><img src="{{ $banner }}" alt="" width="1200" height="240"></div>@endif

    <div class="sh-head">
        @if ($logo)<img class="pdp-seller__logo" src="{{ $logo }}" alt="" width="56" height="56">@else<span class="pdp-seller__logo pdp-seller__logo--none" aria-hidden="true"><x-ds.icon name="store" :size="28" /></span>@endif
        <div>
            <h1>{{ $store->name }}</h1>
            <p class="ds-muted">{{ $place ?: 'Place not given' }}</p>
            @if ($rating['shown'])<x-ds.rating :value="$rating['average']" :count="$rating['count']" href="#store-reviews" />
            @elseif ($rating['count'] > 0)<p class="ds-muted">{{ $rating['count'] }} {{ \Illuminate\Support\Str::plural('review', $rating['count']) }}. A store rating shows from {{ $rating['minimum'] }}.</p>
            @else<p class="ds-muted">No reviews yet.</p>@endif
        </div>
    </div>

    @if ($store->description)<p class="ds-prose pdp-desc">{{ $store->description }}</p>@endif

    <details class="sh-how">
        <summary>
            <span class="sh-how__line">{{ $tally->get('checked', 0) }} {{ $tally->get('checked', 0) === 1 ? 'detail' : 'details' }} checked by InfoDot, {{ $tally->get('claimed', 0) }} claimed by the seller, {{ $measured }} {{ $measured === 1 ? 'measure' : 'measures' }} from orders on Emall.</span>
            <span class="sh-how__cue">How this is measured<x-ds.icon name="chevron-down" :size="16" /></span>
        </summary>
        <div class="sh-how__body">
            <h2 id="trust-h" class="pdp-sub">About this seller</h2>
            <x-ds.seller-trust :rungs="$rungs" title="About this seller" />
            <p class="ds-muted pdp-note">Checked means Emall's own sign-in with InfoDot recorded it. Claimed means the seller typed it and nobody has checked it. Measures come from orders on Emall.</p>
        </div>
    </details>

    <section class="pdp-section" id="products" aria-labelledby="products-h">
        <div class="pdp-section__head"><h2 id="products-h">Products</h2></div>

        @if ($chips)
            <ul class="sh-chips" aria-label="Categories in this store">
                <li><a href="{{ route('stores.show', ['store' => $store] + $query) }}#products" @if (empty($input['category'])) aria-current="true" @endif>All</a></li>
                @foreach ($chips as $c)
                    <li><a href="{{ route('stores.show', ['store' => $store, 'category' => $c['slug']] + $query) }}#products" @if ($c['selected']) aria-current="true" @endif>{{ $c['name'] }} <small>{{ $c['count'] }}</small></a></li>
                @endforeach
            </ul>
        @endif

        <form method="GET" action="{{ route('stores.show', $store) }}#products" class="ds-toolbar" role="search" aria-label="Search this store" style="margin-block: var(--s-4);">
            @if (! empty($input['category']))<input type="hidden" name="category" value="{{ $input['category'] }}">@endif
            <x-ds.input name="q" id="shop-q" :label="'Search '.$store->name" type="search" :value="$input['q'] ?? ''" />
            <x-ds.select name="sort" id="shop-sort" label="Sort by" :selected="$input['sort'] ?? 'relevance'" :options="$sorts" data-autosubmit />
            <x-ds.checkbox name="in_stock" id="shop-stock" value="1" label="In stock only" :checked="! empty($input['in_stock'])" data-autosubmit />
            <noscript><x-ds.button type="submit" variant="quiet">Apply</x-ds.button></noscript>
            <x-ds.button type="submit" icon="search" data-js-only hidden>Search</x-ds.button>
        </form>

        @if ($result->isEmpty())
            <x-ds.empty-state title="Nothing matches" icon="search">{{ $store->name }} has nothing for sale that fits. @foreach ($result->notes as $note){{ $note }} @endforeach
                <x-slot:actions><x-ds.button :href="route('stores.show', $store).'#products'" variant="quiet">Show everything from this store</x-ds.button></x-slot:actions>
            </x-ds.empty-state>
        @else
            <p class="ds-toolbar__count" aria-live="polite">{{ number_format($result->total) }} {{ \Illuminate\Support\Str::plural('product', $result->total) }}</p>
            <div class="ds-grid">
                @foreach ($result->items as $hit)
                    @php($p = $hit->product)
                    <x-ds.product-card :href="route('products.show', ['product' => $p, 'src' => 'search'])" :title="$p->name" :image="$p->images->first()?->cardUrl()" :amount="$p->price" :rating-value="$p->rating_average ?? 0" :rating-count="$p->rating_count ?? 0" :stock="$p->stock">
                        <x-slot:wishlist><x-wish-toggle :product="$p" /></x-slot:wishlist>
                    </x-ds.product-card>
                @endforeach
            </div>
            @if ($result->lastPage() > 1)
                <x-ds.pagination :paginator="new \Illuminate\Pagination\LengthAwarePaginator(array_fill(0, max(0, min($result->perPage, $result->total - ($result->page - 1) * $result->perPage)), null), $result->total, $result->perPage, $result->page, ['path' => route('stores.show', $store), 'query' => request()->except('page')])" />
            @endif
        @endif
    </section>

    <section class="pdp-section" id="delivery-returns" aria-labelledby="policy-h">
            <h2 id="policy-h" class="pdp-sub">Delivery and returns</h2>
            @if ($methods)
                <ul class="pdp-methods">
                    @foreach ($methods as $m)<li><span class="pdp-methods__name">{{ $m['name'] }}</span><span class="pdp-methods__price">{{ $m['price'] }}</span><span class="pdp-methods__when ds-muted">{{ $m['window'] }}</span></li>@endforeach
                </ul>
            @else
                <p class="ds-muted">{{ $store->name }} has not set delivery methods. Delivery is arranged with the seller after you order, and Emall adds no delivery charge.</p>
            @endif
            @if ($store->shipping_policy)<h3 class="pdp-sub" style="margin-block-start: var(--s-4);">Shipping policy</h3><p class="sh-policy">{{ $store->shipping_policy }}</p>@endif
            <h3 class="pdp-sub" style="margin-block-start: var(--s-4);">Returns</h3>
            <p class="sh-policy">You can ask to return an item within {{ $returnDays }} days of it being delivered or collected. If it is faulty or not as described, {{ $store->name }} pays for the return; if you changed your mind, you do.</p>
            @if ($store->return_policy)<p class="sh-policy" style="margin-block-start: var(--s-3);">{{ $store->return_policy }}</p>@endif
    </section>

    <section class="pdp-section" id="store-reviews" aria-labelledby="store-reviews-h">
        <div class="pdp-section__head"><h2 id="store-reviews-h">What buyers say</h2></div>
        @if ($recentReviews->isEmpty())
            <p class="ds-muted">No reviews of this store's products yet.</p>
        @else
            <p class="ds-muted pdp-note">The latest published reviews of products sold by this store.</p>
            <div class="rv-list">
                @foreach ($recentReviews as $review)
                    <x-ds.review-card :author="$review->user?->publicName() ?? 'Buyer'" :rating="$review->rating" :body="$review->body ?: 'No written comment.'" :date="$review->created_at" :verified="(bool) $review->verified_purchase">
                        <p class="ds-muted">On <a href="{{ route('products.show', $review->product_id) }}#review-{{ $review->id }}">{{ $review->product?->name ?? 'a product' }}</a></p>
                    </x-ds.review-card>
                @endforeach
            </div>
        @endif
    </section>

    <section class="pdp-section" id="contact" aria-labelledby="contact-h">
        <div class="pdp-section__head"><h2 id="contact-h">Write to the store</h2></div>
        @auth
            @if (app(\App\Services\Community\StoreMembers::class)->canSpeakFor(auth()->user(), $store))
                <p class="ds-muted">This is your store. Messages from shoppers arrive in your notifications.</p>
            @else
                <form method="POST" action="{{ route('stores.message', $store) }}" class="sh-contact">
                    @csrf
                    <p class="ds-muted">{{ $store->name }}'s people are told in the app and by e-mail. They see your name and the e-mail address on your account so they can answer you. We do not show you the store's e-mail address.</p>
                    @if ($products->isNotEmpty())
                        <x-ds.select name="product_id" id="contact-product" label="About a product (optional)" placeholder="Nothing in particular" :options="$products->pluck('name', 'id')->all()" />
                    @endif
                    <x-ds.field label="Your message" id="contact-body" hint="10 to 1000 characters." :error="$enquiry->first('body')">
                        <textarea class="ds-textarea" id="contact-body" name="body" rows="4" maxlength="1000" required @if ($enquiry->has('body')) aria-invalid="true" aria-describedby="contact-body-hint contact-body-error" @else aria-describedby="contact-body-hint" @endif>{{ old('body') }}</textarea>
                    </x-ds.field>
                    <div><x-ds.button type="submit" icon="message">Send message</x-ds.button></div>
                </form>
            @endif
        @else
            <p class="rv-why"><x-ds.icon name="info" :size="18" /><span><a href="{{ route('login') }}">Sign in with InfoDot</a> to write to the store. Accounts come from InfoDot, and signing in means the store can answer you.</span></p>
        @endauth
    </section>

    @if (! $closed)
        <section class="pdp-section pdp-report" id="report-store" aria-label="Report this store">
            @auth
                @unless (app(\App\Services\Community\StoreMembers::class)->canSpeakFor(auth()->user(), $store))
                    <x-report-form :action="route('stores.report', $store)" :bag="'reportstore'.$store->id" label="Report this store" what="this store" />
                @endunless
            @else
                <p class="ds-muted pdp-inline"><x-ds.icon name="flag" :size="16" /><span><a href="{{ route('login') }}">Sign in with InfoDot</a> to report this store.</span></p>
            @endauth
        </section>
    @endif
</x-store-layout>
