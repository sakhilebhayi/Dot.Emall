{{--
  The buyer's landing (R-50): where someone without a shop arrives after signing in. One primary action: Shop the mall when something is listed,
  Open a shop when the mall is still empty (App\Support\Landing). Every figure is a real record: the listings and their prices are the newest
  browsable products, the orders are this person's own. The account overview this page used to redirect to is one link away.
--}}
@php $open = $shelf->isNotEmpty(); $who = $company ?: 'your business'; @endphp
<x-account-layout>
    <x-slot:title>Welcome</x-slot:title>

    <div class="ld" data-landing="buyer">
        @include('landing._masthead', ['role' => 'for'])

        <div class="ld-hero">
            <div class="ld-hero__text">
                @if ($open)
                    <h1>Shop every store in one mall.</h1>
                    <p class="ld-hero__lead">Dot.Emall is the marketplace of the Dot ecosystem. Every label shows the price, what delivery costs to your address and the total, before you pay.</p>
                @else
                    <h1>The shelves are still empty.</h1>
                    <p class="ld-hero__lead">Dot.Emall is the marketplace of the Dot ecosystem, and no product is listed yet. Shops come first: if {{ $who }} has something to sell, it can be one of them.</p>
                @endif
                <div class="ld-hero__act">
                    <x-ds.button :href="$primary['href']" size="lg" icon-right="arrow-right" data-primary-action="">{{ $primary['label'] }}</x-ds.button>
                </div>
            </div>

            <section class="ld-shelf" aria-labelledby="ld-shelf-h">
                @if ($open)
                    <h2 id="ld-shelf-h">New on the shelves</h2>
                    <div class="ld-shelf__row">
                        @foreach ($shelf as $p)
                            <x-ds.product-card :href="route('products.show', $p)" :title="$p->name" :image="$p->images->first()?->cardUrl()" :store="$p->store?->name" :amount="$p->price" :stock="$p->stock" ratio="1 / 1" :eager="true" />
                        @endforeach
                    </div>
                    <p class="ld-basis">The newest listings in the mall, at the price each seller set.</p>
                @else
                    <h2 id="ld-shelf-h" class="ds-visually-hidden">The mall today</h2>
                    <x-ds.empty-state title="Nothing is listed yet" icon="tag" class="ld-empty">Products appear here once a shop has listed them. Until then there is nothing to buy.</x-ds.empty-state>
                @endif
            </section>
        </div>

        <div class="ld-context">
            <section aria-labelledby="ld-orders-h">
                <div class="ld-context__head">
                    <h2 id="ld-orders-h">Your orders</h2>
                    @if ($orders->isNotEmpty())<a href="{{ route('orders.index') }}">All orders</a>@endif
                </div>
                @if ($orders->isEmpty())
                    <p class="ld-none">You have not ordered anything yet. An order shows up here as soon as you place it.</p>
                @else
                    @if ($awaiting > 0)
                        <p class="ld-note"><x-ds.icon name="clock" :size="18" /><span>{{ $awaiting }} {{ $awaiting === 1 ? 'order is' : 'orders are' }} waiting for payment. <a href="{{ route('orders.index', ['status' => 'awaiting_payment']) }}">See {{ $awaiting === 1 ? 'it' : 'them' }}</a>.</span></p>
                    @endif
                    <ul class="ld-list">
                        @foreach ($orders as $o)
                            <li>
                                <span><a href="{{ route('orders.show', $o) }}" class="ds-code">{{ $o->reference() }}</a> <span class="ds-muted">{{ $o->created_at->format('j M Y') }}, {{ $o->items->count() }} {{ $o->items->count() === 1 ? 'item' : 'items' }}, {{ $o->money($o->totalCents()) }}</span></span>
                                <x-ds.status kind="order" :state="$statuses[$o->id]" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <nav class="ld-more" aria-labelledby="ld-more-h">
                <h2 id="ld-more-h">Also here</h2>
                <ul>
                    <li><a href="{{ route('account.home') }}">Account overview</a><span>Orders, returns, wishlist, addresses and alerts.</span></li>
                    @if ($open)
                        <li><a href="{{ route('sell.start') }}">Open a shop</a><span>Sell to every buyer in the mall{{ $company ? ' as '.$company : '' }}.</span></li>
                    @else
                        <li><a href="{{ route('products.index') }}">Look at the mall</a><span>The catalogue as it stands today.</span></li>
                    @endif
                    <li><a href="{{ route('help.index') }}">Help</a><span>Delivery, returns and how payment works.</span></li>
                    @if ($isOperator)<li><a href="{{ route('operator.home') }}">Operator desk</a><span>Listings, stores and payments that need a person.</span></li>@endif
                </ul>
            </nav>
        </div>
    </div>
</x-account-layout>
