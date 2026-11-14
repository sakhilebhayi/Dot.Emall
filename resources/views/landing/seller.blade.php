{{--
  The seller's landing (R-50): where someone with a shop arrives after signing in. One primary action: Add your first product while the shop has none,
  Manage your products once it has (App\Support\Landing). The listings are the shop's own newest products at their real prices; "Needs you" is
  SellerDesk::needsYou, the same counts as the seller dashboard, which is one link away with everything it had.
--}}
@php
    $has = $listings->isNotEmpty();
    $plural = fn (int $n, string $one, ?string $many = null) => $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    $state = fn ($p) => match (true) { $p->status === 'rejected' => 'rejected', $p->status === 'pending_approval' => 'pending_review', ! $p->is_active => 'unlisted', default => null };
@endphp
<x-seller-layout>
    <x-slot:title>Welcome</x-slot:title>

    <div class="ld" data-landing="seller">
        @include('landing._masthead', ['role' => 'running', 'company' => $store->name])

        <div class="ld-hero">
            <div class="ld-hero__text">
                @if ($has)
                    <h1>Your shelf, kept honest.</h1>
                    <p class="ld-hero__lead">Dot.Emall is where {{ $store->name }} sells to every buyer in the Dot ecosystem. Keep each price and stock count true here, because the label a shopper reads is built from them.</p>
                @else
                    <h1>Stock your first shelf.</h1>
                    <p class="ld-hero__lead">Dot.Emall is where {{ $store->name }} sells to every buyer in the Dot ecosystem. A listing starts with a name, a price, your stock and a photograph.</p>
                @endif
                <div class="ld-hero__act">
                    <x-ds.button :href="$primary['href']" size="lg" icon-right="arrow-right" data-primary-action="">{{ $primary['label'] }}</x-ds.button>
                </div>
            </div>

            <section class="ld-shelf" aria-labelledby="ld-shelf-h">
                @if ($has)
                    <h2 id="ld-shelf-h">Your newest listings</h2>
                    <div class="ld-shelf__row">
                        @foreach ($listings as $p)
                            <x-ds.product-card :href="route('seller.products.edit', $p)" :title="$p->name" :image="$p->images->first()?->cardUrl()" :amount="$p->price" :stock="$p->stock" :state="$state($p)" ratio="1 / 1" :eager="true" />
                        @endforeach
                    </div>
                    <p class="ld-basis">Each opens in the editor, at the price and stock you last saved.</p>
                @else
                    <h2 id="ld-shelf-h" class="ds-visually-hidden">Your shelf today</h2>
                    <x-ds.empty-state title="No products yet" icon="box" class="ld-empty">Your shop has nothing listed, so shoppers cannot buy from it yet. Your listings will show here with their real price and stock.</x-ds.empty-state>
                @endif
            </section>
        </div>

        <div class="ld-context">
            <section aria-labelledby="ld-needs-h">
                <div class="ld-context__head">
                    <h2 id="ld-needs-h">Needs you</h2>
                    <a href="{{ route('seller.dashboard') }}">Seller dashboard</a>
                </div>
                @if ($needs['total'] === 0)
                    <p class="ld-none">Nothing needs you right now. Orders to ship, returns, low stock, questions and rejected listings show up here when they do.</p>
                @else
                    <ul class="ld-list">
                        @if ($needs['toShip'])<li><span>{{ $plural($needs['toShip'], 'parcel') }} to pack and ship</span><a href="{{ route('seller.orders.index', ['tab' => 'to_ship']) }}">Open orders</a></li>@endif
                        @if ($needs['returns'])<li><span>{{ $plural($needs['returns'], 'return') }} to answer</span><a href="{{ route('seller.returns') }}">Answer returns</a></li>@endif
                        @if ($needs['lowStock'])<li><span>{{ $plural($needs['lowStock'], 'product') }} at or below your low-stock level</span><a href="{{ route('seller.products.index', ['stock' => 'low']) }}">See {{ $needs['lowStock'] === 1 ? 'it' : 'them' }}</a></li>@endif
                        @if ($needs['questions'])<li><span>{{ $plural($needs['questions'], 'question') }} to answer</span><a href="{{ route('seller.feedback', ['tab' => 'questions']) }}">Answer</a></li>@endif
                        @if ($needs['reviews'])<li><span>{{ $plural($needs['reviews'], 'review') }} without a reply</span><a href="{{ route('seller.feedback', ['tab' => 'reviews']) }}">Reply</a></li>@endif
                        @foreach ($needs['rejected'] as $p)<li><span>{{ $p->name }} was rejected</span><a href="{{ route('seller.products.edit', $p) }}">Fix and resend</a></li>@endforeach
                    </ul>
                @endif
                @if ($needs['pendingListings'])<p class="ld-basis">{{ $plural($needs['pendingListings'], 'listing') }} {{ $needs['pendingListings'] === 1 ? 'is' : 'are' }} waiting for the Dot.Emall team to review.</p>@endif
            </section>

            <nav class="ld-more" aria-labelledby="ld-more-h">
                <h2 id="ld-more-h">Also here</h2>
                <ul>
                    <li><a href="{{ route('seller.dashboard') }}">Seller dashboard</a><span>Sales, your ledger, recent orders and how you are doing.</span></li>
                    @if ($has)<li><a href="{{ route('seller.products.create') }}">New product</a><span>Add another listing to your shop.</span></li>@endif
                    <li><a href="{{ route('seller.orders.index') }}">Orders</a><span>Every parcel for your shop.</span></li>
                    <li><a href="{{ route('account.home') }}">Your buyer account</a><span>What you have bought yourself.</span></li>
                </ul>
            </nav>
        </div>
    </div>
</x-seller-layout>
