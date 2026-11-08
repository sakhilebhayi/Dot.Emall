@php
    use App\Support\Shop;
    $plural = fn (int $n, string $one, ?string $many = null) => $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    $cur = Shop::currency();
    $bal = $balance[$cur] ?? ['held' => 0, 'released' => 0, 'commission' => 0];
    $hasLedger = ! empty($balance);
    $hours = fn (?float $h) => $h === null ? null : ($h < 1 ? 'under an hour' : ($h < 48 ? round($h).' hours' : round($h / 24, 1).' days'));
@endphp
<x-seller-layout>
    <x-slot:title>Seller dashboard</x-slot:title>
    <x-slot:heading>{{ $store->name }}</x-slot:heading>
    <x-slot:lead>What needs you today, then how the store is doing.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button variant="primary" :href="route('seller.products.create')" icon="plus">New product</x-ds.button>
    </x-slot:actions>

    <div class="sd-page">
        <section aria-labelledby="d-needs">
            <h2 id="d-needs" class="sd-h2" style="margin-block-end: var(--s-3);">Needs you</h2>
            @if ($needs['total'] === 0)
                <p class="sd-needs__none" style="border-top: 2px solid var(--ink);">Nothing needs you right now. Orders to ship, returns to answer, low stock, questions, reviews and rejected listings all show up here when they do.</p>
            @else
                <ul class="sd-needs">
                    @if ($needs['toShip'])
                        <li><span class="sd-needs__n">{{ $needs['toShip'] }}</span><span class="sd-needs__text"><strong>{{ $plural($needs['toShip'], 'parcel') }} to pack and ship</strong><small>Paid and waiting for you.</small></span><x-ds.button variant="primary" size="sm" :href="route('seller.orders.index', ['tab' => 'to_ship'])">Open orders</x-ds.button></li>
                    @endif
                    @if ($needs['returns'])
                        <li><span class="sd-needs__n">{{ $needs['returns'] }}</span><span class="sd-needs__text"><strong>{{ $plural($needs['returns'], 'return') }} to answer</strong><small>@if ($needs['returnsDueDays'] !== null){{ $needs['returnsDueDays'] === 0 ? 'The oldest goes to the Emall team today if unanswered.' : 'The oldest goes to the Emall team in '.$plural($needs['returnsDueDays'], 'day').' if unanswered.' }}@endif</small></span><x-ds.button variant="primary" size="sm" :href="route('seller.returns')">Answer returns</x-ds.button></li>
                    @endif
                    @if ($needs['lowStock'])
                        <li><span class="sd-needs__n">{{ $needs['lowStock'] }}</span><span class="sd-needs__text"><strong>{{ $needs['lowStock'] === 1 ? 'Product' : 'Products' }} at or below your low-stock level</strong><small>{{ $needs['lowStockSample']->map(fn ($v) => $v->product->name.' ('.$v->stock.' left)')->implode(', ') }}@if ($needs['lowStock'] > 3) and more @endif</small></span><x-ds.button variant="quiet" size="sm" :href="route('seller.products.index', ['stock' => 'low'])">See them</x-ds.button></li>
                    @endif
                    @if ($needs['questions'])
                        <li><span class="sd-needs__n">{{ $needs['questions'] }}</span><span class="sd-needs__text"><strong>{{ $plural($needs['questions'], 'question') }} to answer</strong><small>Shoppers asked about your products.</small></span><x-ds.button variant="quiet" size="sm" :href="route('seller.feedback', ['tab' => 'questions'])">Answer</x-ds.button></li>
                    @endif
                    @if ($needs['reviews'])
                        <li><span class="sd-needs__n">{{ $needs['reviews'] }}</span><span class="sd-needs__text"><strong>{{ $plural($needs['reviews'], 'review') }} without a reply</strong><small>You can reply once to each review.</small></span><x-ds.button variant="quiet" size="sm" :href="route('seller.feedback', ['tab' => 'reviews'])">Reply</x-ds.button></li>
                    @endif
                    @foreach ($needs['rejected'] as $p)
                        <li><span class="sd-needs__n"><x-ds.icon name="x-octagon" :size="26" /></span><span class="sd-needs__text"><strong>{{ $p->name }} was rejected</strong><small>{{ $p->rejected_reason ?: 'No reason was recorded.' }}</small></span><x-ds.button variant="quiet" size="sm" :href="route('seller.products.edit', $p)">Fix and resend</x-ds.button></li>
                    @endforeach
                </ul>
            @endif
            @if ($needs['awaitingPayment'] || $needs['pendingListings'])
                <p class="sd-quiet" style="margin-block-start: var(--s-3);">
                    @if ($needs['awaitingPayment']){{ $plural($needs['awaitingPayment'], 'parcel') }} {{ $needs['awaitingPayment'] === 1 ? 'is' : 'are' }} waiting for the buyer to pay: do not ship {{ $needs['awaitingPayment'] === 1 ? 'it' : 'them' }} yet. @endif
                    @if ($needs['pendingListings']){{ $plural($needs['pendingListings'], 'listing') }} {{ $needs['pendingListings'] === 1 ? 'is' : 'are' }} waiting for the Emall team to review. @endif
                </p>
            @endif
        </section>

        <div class="sd-cols">
            <section aria-labelledby="d-sales" class="sd-stack">
                <div class="sd-cluster" style="justify-content: space-between;">
                    <h2 id="d-sales" class="sd-h2">Sales</h2>
                    <nav class="sd-period" aria-label="Period">
                        @foreach ($periods as $days => $label)<a href="{{ route('seller.dashboard', ['period' => $days]) }}" @if ($window['days'] === $days) aria-current="true" @endif>{{ $label }}</a>@endforeach
                    </nav>
                </div>
                <div class="sd-lead">
                    <div class="sd-lead__figure">
                        <span class="sd-quiet">{{ $window['label'] }}, items after any discount, VAT included, delivery left out</span>
                        @if ($sales['has_data'])
                            <span class="sd-lead__value">{{ Shop::money($sales['sales_cents']) }}</span>
                            <span class="sd-muted">from {{ $plural($sales['orders'], 'order') }} and {{ $plural($sales['units'], 'item') }}.@if ($previous['has_data']) The {{ $window['days'] }} days before: {{ Shop::money($previous['sales_cents']) }} from {{ $plural($previous['orders'], 'order') }}.@endif</span>
                        @else
                            <span class="sd-lead__value sd-lead__value--empty">No data yet</span>
                            <span class="sd-muted">No paid orders in this period. Sales appear here once a buyer has paid.</span>
                        @endif
                    </div>
                    @if ($sales['has_data'])
                        <div class="sd-facts">
                            <x-ds.stat label="Orders" :value="(string) $sales['orders']" />
                            <x-ds.stat label="Items sold" :value="(string) $sales['units']" />
                            <x-ds.stat label="Average order" :value="Shop::money((int) $sales['aov_cents'])" note="Items per order" />
                        </div>
                    @endif
                </div>
                @if ($sales['other_currencies'])
                    <div class="sd-stack sd-stack--tight">
                        <p class="sd-quiet">Sold in other currencies in the same period. Each currency is its own figure: they are never added to the one above.</p>
                        <dl class="sd-defs">
                            @foreach ($sales['other_currencies'] as $code => $other)
                                <dt>{{ $code }}</dt><dd><strong class="sd-num">{{ Shop::money($other['cents'], $code) }}</strong> from {{ $plural($other['orders'], 'order') }}.</dd>
                            @endforeach
                        </dl>
                    </div>
                @endif
                <p><a class="ds-btn ds-btn--link" href="{{ route('seller.analytics', ['period' => $window['days']]) }}">Open analytics</a></p>
            </section>

            <aside aria-labelledby="d-ledger" class="sd-stack">
                <h2 id="d-ledger" class="sd-h2">Your ledger</h2>
                @if ($hasLedger)
                    <div class="sd-stack sd-stack--tight">
                        <x-ds.stat label="Held, waiting out the return window" :value="Shop::money($bal['held'])" />
                        <x-ds.stat label="Released to you" :value="Shop::money($bal['released'])" />
                    </div>
                    <p class="sd-quiet">After Emall's fee. Reversals from cancellations and refunds are already taken off. Released earnings are paid out by arrangement with the Emall team: there are no automatic payouts yet.</p>
                @else
                    <p class="sd-muted">No earnings recorded yet. A sale is added the moment the buyer pays.</p>
                @endif
                <p><a class="ds-btn ds-btn--link" href="{{ route('seller.ledger') }}">Open ledger</a></p>
            </aside>
        </div>

        <section aria-labelledby="d-recent">
            <div class="sd-cluster" style="justify-content: space-between; margin-block-end: var(--s-3);">
                <h2 id="d-recent" class="sd-h2">Recent orders</h2>
                <a class="ds-btn ds-btn--link" href="{{ route('seller.orders.index') }}">All orders</a>
            </div>
            @if ($recent->isEmpty())
                <p class="sd-muted">No orders for your store yet. A parcel appears here the moment a buyer places an order.</p>
            @else
                <x-ds.table caption="Recent orders">
                    <x-slot:head>
                        <tr><th scope="col">Placed</th><th scope="col">Order</th><th scope="col">Ship to</th><th scope="col" class="is-num">Items</th><th scope="col">Status</th><th scope="col" class="is-num">Your items</th></tr>
                    </x-slot:head>
                    @foreach ($recent as $row)
                        <tr>
                            <td data-label="Placed">{{ $row['order']->created_at->format('j M Y') }}</td>
                            <td data-label="Order"><a href="{{ route('seller.orders.show', $row['group']) }}">{{ $row['order']->reference() }}</a></td>
                            <td data-label="Ship to">{{ $row['buyerName'] ?? 'Not shown after dispatch' }}</td>
                            <td data-label="Items" class="is-num">{{ $row['lineCount'] }}</td>
                            <td data-label="Status">@if ($row['awaitingPayment'])<x-ds.status state="awaiting_payment" />@else<x-ds.status :state="$row['status']->value" />@endif</td>
                            <td data-label="Your items" class="is-num"><strong>{{ $row['order']->money($row['netCents']) }}</strong></td>
                        </tr>
                    @endforeach
                </x-ds.table>
            @endif
        </section>

        <section aria-labelledby="d-perf">
            <h2 id="d-perf" class="sd-h2" style="margin-block-end: var(--s-3);">How you are doing</h2>
            <dl class="sd-defs">
                <dt>Shipped within your packing time</dt>
                <dd>
                    @if ($fulfilment['rate'] !== null)<strong class="sd-num">{{ rtrim(rtrim(number_format($fulfilment['rate'], 1), '0'), '.') }}%</strong> of {{ $plural($fulfilment['due'], 'parcel') }} due in the {{ strtolower($window['label']) }}.@if ($fulfilment['due'] < config('emall.seller.trust_min_sample', 5)) That is a small number, so shoppers do not see it until you have {{ config('emall.seller.trust_min_sample', 5) }} orders.@endif
                    @else No data yet: no parcel was due in this period. @endif
                    <br><span class="sd-quiet">Counted from payment, in working days, against the handling time on the shipping method the buyer chose.</span>
                </dd>
                <dt>Time from payment to shipped</dt>
                <dd>@if ($fulfilment['median_hours'] !== null)<strong>{{ $hours($fulfilment['median_hours']) }}</strong> (middle value of {{ $plural($fulfilment['shipped'], 'shipped parcel') }}).@else No data yet.@endif</dd>
                <dt>Average rating</dt>
                <dd>@if ($reviews['average'] !== null)<strong class="sd-num">{{ number_format($reviews['average'], 1) }}</strong> out of 5 from {{ $plural($reviews['count'], 'review') }} on your products.@else No data yet: none of your products has a review. @endif</dd>
                <dt>Answering questions</dt>
                <dd>@if ($responses['questions']['hours'] !== null)<strong>{{ $hours($responses['questions']['hours']) }}</strong> to a first answer (middle value of {{ $plural($responses['questions']['sample'], 'question') }}).@else No data yet: you have not answered a question.@endif</dd>
                <dt>Replying to reviews</dt>
                <dd>@if ($responses['reviews']['hours'] !== null)<strong>{{ $hours($responses['reviews']['hours']) }}</strong> to a reply (middle value of {{ $plural($responses['reviews']['sample'], 'review') }}).@else No data yet: you have not replied to a review.@endif</dd>
            </dl>
        </section>
    </div>
</x-seller-layout>
