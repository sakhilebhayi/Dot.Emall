@php
    $images = $product->images->map(fn ($i) => ['src' => $i->url(), 'alt' => $product->name])->values()->all();
    $crumbs = array_values(array_filter([
        ['label' => 'Home', 'href' => url('/')],
        ['label' => 'Products', 'href' => route('products.index')],
        $product->category ? ['label' => $product->category->name, 'href' => route('products.index', ['category' => $product->category->id])] : null,
        ['label' => $product->name],
    ]));
    $priceCents = \App\Support\Money::toCents($product->price);
    $canBuy = auth()->check() && $product->is_active && $product->store->is_active && $product->stock > 0 && $product->store->owner_id !== auth()->id();
    $stockState = \App\Support\Status::stockState((int) $product->stock);
@endphp
<x-store-layout :bottom-bar="$canBuy">
    <x-slot:title>{{ $product->name }}</x-slot:title>
    <x-slot:description>{{ \Illuminate\Support\Str::limit(strip_tags((string) ($product->description ?: $product->name.' from '.$product->store->name)), 155) }}</x-slot:description>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="$crumbs" /></x-slot:breadcrumbs>

    @if (session('status'))<x-ds.alert tone="pos" style="margin-top: var(--s-4);">{{ session('status') }}</x-ds.alert>
@endif
    @if ($errors->any())
        <x-ds.alert tone="crit" style="margin-top: var(--s-4);">@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</x-ds.alert>
    @endif

    <div class="ds-pdp">
        <x-ds.product-gallery :images="$images" layout="side" />

        <div class="ds-pdp__buy">
            <div class="ds-pdp__head">
                <p class="ds-pdp__store">Sold by {{ $product->store->name }}</p>
                <h1>{{ $product->name }}</h1>
                <x-ds.rating :value="$product->rating_average ?? 0" :count="$product->rating_count ?? 0" href="#reviews" />
            </div>

            <x-ds.price-label :price-cents="$priceCents" :from="$product->store->name" :previous-cents="null" />

            <div class="ds-pdp__stock">
                <x-ds.status kind="product" :state="$stockState" :quantity="$product->stock" />
                @if ($stockState === 'in_stock' && $product->stock > 0)<span class="ds-muted">Ready to ship from the seller</span>@endif
            </div>

            @guest
                @if ($product->stock > 0)
                    <div class="ds-stack" style="--stack: var(--s-2);">
                        <x-ds.button :href="route('login')" size="lg" block icon="user">Sign in to buy</x-ds.button>
                        <p class="ds-muted" style="font-size: var(--text-sm);">Accounts come from InfoDot. You come back to this page after signing in.</p>
                    </div>
                @endif
            @endguest
            @auth
                @if ($canBuy)
                    <form method="POST" action="{{ route('cart.store') }}" id="buy-form" class="ds-pdp__form">
                        @csrf
                        <input type="hidden" name="product_id" value="{{ $product->id }}">
                        <x-ds.quantity-stepper :max="min(99, $product->stock)" />
                        <x-ds.button type="submit" size="lg" icon="cart" class="ds-pdp__add">Add to cart</x-ds.button>
                    </form>
                @elseif ($product->store->owner_id === auth()->id())
                    <x-ds.alert tone="info">This is your own listing, so it cannot be added to a cart.</x-ds.alert>
                @endif
            @endauth

            @if ($product->description)
                <section aria-labelledby="about-h"><h2 id="about-h" style="font-size: var(--text-md); margin-bottom: var(--s-2);">About this product</h2><p class="ds-prose" style="color: var(--ink-2); white-space: pre-line;">{{ $product->description }}</p></section>
            @endif
        </div>
    </div>

    <section class="ds-section" id="reviews" aria-labelledby="reviews-h">
        <div class="ds-section__head"><h2 id="reviews-h">Reviews</h2>@if (($product->rating_count ?? 0) > 0)<x-ds.rating :value="$product->rating_average" :count="$product->rating_count" />@endif</div>

        @auth
            @if ($canReview)
                <form method="POST" action="{{ route('reviews.store', $product) }}" class="ds-panel ds-stack" style="--stack: var(--s-3); max-width: 40rem; margin-bottom: var(--s-5);">
                    @csrf
                    <h3 style="font-size: var(--text-md);">Leave a review</h3>
                    <x-ds.select name="rating" label="Rating" placeholder="Choose a rating" required :options="[5 => '5 stars', 4 => '4 stars', 3 => '3 stars', 2 => '2 stars', 1 => '1 star']" />
                    <x-ds.textarea name="body" label="Comments (optional)" rows="3" />
                    <div><x-ds.button type="submit">Post review</x-ds.button></div>
                </form>
            @elseif (! $hasReviewed)
                <p class="ds-muted" style="margin-bottom: var(--s-4);">Reviews come from buyers: you can review this product after an order containing it has been delivered.</p>
            @endif
        @endauth

        @if ($product->reviews->isEmpty())
            <x-ds.empty-state title="No reviews yet" icon="message">When a buyer reviews this product, their review shows here.</x-ds.empty-state>
        @else
            @foreach ($product->reviews as $review)
                <x-ds.review-card :author="$review->user?->publicName() ?? 'Buyer'" :rating="$review->rating" :body="$review->body ?: 'No comment.'" :date="$review->created_at">
                    @if (auth()->id() === $review->user_id)
                        <form method="POST" action="{{ route('reviews.destroy', $review) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="link" size="sm">Delete my review</x-ds.button></form>
                    @endif
                </x-ds.review-card>
            @endforeach
        @endif
    </section>

    @if ($canBuy)
        <div class="ds-buybar">
            <x-ds.price :cents="$priceCents" size="lg" />
            <x-ds.button type="submit" form="buy-form" icon="cart">Add to cart</x-ds.button>
        </div>
    @endif
</x-store-layout>
