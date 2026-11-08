@php
    use App\Support\Shop;
    $pl = fn (int $n, string $one, ?string $many = null) => $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    $pct = fn (?float $v) => $v === null ? null : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    $hrs = fn (?float $h) => $h === null ? null : ($h < 1 ? 'under an hour' : ($h < 48 ? round($h).' hours' : round($h / 24, 1).' days'));
    $max = max(1, collect($series)->max('cents'));
    $bestDay = collect($series)->sortByDesc('cents')->first();
    $topProducts = $products->take(15);
@endphp
<x-seller-layout>
    <x-slot:title>Analytics</x-slot:title>
    <x-slot:heading>Analytics</x-slot:heading>
    <x-slot:lead>Counted from your own orders and what shoppers did on your listings. Where there is nothing to count, it says so.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button variant="quiet" :href="route('seller.analytics.export', ['what' => 'orders', 'period' => $window['days']])" icon="upload">Orders CSV</x-ds.button>
        <x-ds.button variant="quiet" :href="route('seller.analytics.export', ['what' => 'products', 'period' => $window['days']])" icon="upload">Products CSV</x-ds.button>
    </x-slot:actions>

    <div class="sd-page">
        <nav class="sd-period" aria-label="Period">
            @foreach ($periods as $days => $label)<a href="{{ route('seller.analytics', ['period' => $days]) }}" @if ($window['days'] === $days) aria-current="true" @endif>{{ $label }}</a>@endforeach
        </nav>

        <section aria-labelledby="a-sales" class="sd-stack">
            <h2 id="a-sales" class="sd-h2">Sales</h2>
            <div class="sd-lead">
                <div class="sd-lead__figure">
                    <span class="sd-quiet">{{ $window['label'] }}, {{ $window['from']->format('j M') }} to {{ $window['to']->format('j M Y') }}</span>
                    @if ($sales['has_data'])
                        <span class="sd-lead__value">{{ Shop::money($sales['sales_cents']) }}</span>
                        <span class="sd-muted">@if ($previous['has_data'])The {{ $window['days'] }} days before: {{ Shop::money($previous['sales_cents']) }} from {{ $pl($previous['orders'], 'order') }}.@else Nothing was sold in the {{ $window['days'] }} days before, so there is nothing to compare with.@endif</span>
                    @else
                        <span class="sd-lead__value sd-lead__value--empty">No data yet</span>
                        <span class="sd-muted">No paid orders in this period.</span>
                    @endif
                </div>
                @if ($sales['has_data'])
                    <div class="sd-facts">
                        <x-ds.stat label="Orders" :value="(string) $sales['orders']" />
                        <x-ds.stat label="Items sold" :value="(string) $sales['units']" />
                        <x-ds.stat label="Average order" :value="Shop::money((int) $sales['aov_cents'])" note="Your items per order" />
                    </div>
                @endif
            </div>
            @if ($sales['has_data'])
                <figure style="margin: 0;">
                    <div class="sd-bars" role="img" aria-label="Sales by {{ count($series) > 60 ? 'week' : 'day' }}. Highest: {{ $bestDay['label'] }}, {{ Shop::money($bestDay['cents']) }}. The same figures are in the table below.">
                        @foreach ($series as $pt)<span style="--h: {{ round($pt['cents'] / $max * 100, 1) }}%;" @if ($pt['cents'] === 0) data-zero="true" @endif aria-hidden="true"></span>@endforeach
                    </div>
                    <div class="sd-axis" aria-hidden="true"><span>{{ $series[0]['label'] }}</span><span>Highest {{ count($series) > 60 ? 'week' : 'day' }}: {{ Shop::money($max) }}. The baseline is R0.00</span><span>{{ end($series)['label'] }}</span></div>
                </figure>
                <details class="sd-stack sd-stack--tight"><summary class="ds-btn ds-btn--link" style="cursor: pointer;">Show the figures as a table</summary>
                    <x-ds.table :caption="'Sales by '.(count($series) > 60 ? 'week' : 'day')" :stack="false">
                        <x-slot:head><tr><th scope="col">{{ count($series) > 60 ? 'Week' : 'Day' }}</th><th scope="col" class="is-num">Sales</th></tr></x-slot:head>
                        @foreach ($series as $pt)<tr><td>{{ $pt['label'] }}</td><td class="is-num">{{ Shop::money($pt['cents']) }}</td></tr>@endforeach
                    </x-ds.table>
                </details>
            @endif
            @if ($sales['other_currencies'])<p class="sd-quiet">Also sold in {{ collect($sales['other_currencies'])->map(fn ($o, $code) => Shop::money($o['cents'], $code).' ('.$pl($o['orders'], 'order').')')->implode('; ') }}. Currencies are never added together, so these are not in the figures here.</p>@endif
        </section>

        <section aria-labelledby="a-traffic" class="sd-stack">
            <h2 id="a-traffic" class="sd-h2">Interest in your listings</h2>
            @if ($engagement['has_data'])
                <div class="sd-facts">
                    <x-ds.stat label="People who opened a listing" :value="(string) $engagement['views']" note="Each person counts once per listing per day" />
                    <x-ds.stat label="Who came from a search" :value="(string) $engagement['from_search']" note="Opens that began in Emall search" />
                    <x-ds.stat label="Added to a cart" :value="(string) $engagement['carts']" />
                </div>
            @else
                <p class="sd-muted"><strong>No data yet.</strong> Nobody has opened or added one of your listings to a cart in this period, or it has not been recorded yet.</p>
            @endif
            <p class="sd-quiet">Emall does not keep which words someone searched for against which listing, so it cannot show you search terms. It does count how many people reached your listings from a search.</p>
        </section>

        <section aria-labelledby="a-products" class="sd-stack">
            <h2 id="a-products" class="sd-h2">Product performance</h2>
            @if ($topProducts->isEmpty())
                <p class="sd-muted"><strong>No data yet.</strong> A product shows here once it has been viewed, added to a cart or sold in this period.</p>
            @else
                <x-ds.table caption="Listings with views, carts or sales in this period" :stack="true">
                    <x-slot:head><tr><th scope="col">Product</th><th scope="col" class="is-num">Views</th><th scope="col" class="is-num">Added to cart</th><th scope="col" class="is-num">Orders</th><th scope="col" class="is-num">Items</th><th scope="col" class="is-num">Sales</th><th scope="col" class="is-num">Orders per view</th></tr></x-slot:head>
                    @foreach ($topProducts as $r)
                        <tr>
                            <td data-label="Product"><a href="{{ route('seller.products.edit', $r['product']) }}">{{ $r['product']->name }}</a></td>
                            <td data-label="Views" class="is-num">{{ $r['views'] }}</td>
                            <td data-label="Added to cart" class="is-num">{{ $r['carts'] }}</td>
                            <td data-label="Orders" class="is-num">{{ $r['orders'] }}</td>
                            <td data-label="Items" class="is-num">{{ $r['units'] }}</td>
                            <td data-label="Sales" class="is-num">{{ Shop::money($r['sales_cents']) }}</td>
                            <td data-label="Orders per view" class="is-num">{{ $r['orders_per_view'] === null ? 'No views' : $pct($r['orders_per_view']) }}</td>
                        </tr>
                    @endforeach
                </x-ds.table>
                @if ($products->count() > 15)<p class="sd-quiet">The top 15 of {{ $products->count() }} are shown. The Products CSV has them all.</p>@endif
            @endif
        </section>

        <div class="sd-cols sd-cols--even">
            <section aria-labelledby="a-inv" class="sd-stack">
                <h2 id="a-inv" class="sd-h2">Inventory now</h2>
                @if ($inventory['has_data'])
                    <x-ds.dl>
                        <dt>Stock value</dt><dd><strong>{{ Shop::money($inventory['value_cents']) }}</strong> at your selling prices, for {{ $pl($inventory['units'], 'item') }} on live listings</dd>
                        <dt>Low on stock</dt><dd>{{ $inventory['low'] }} @if ($inventory['low']) <a href="{{ route('seller.products.index', ['stock' => 'low']) }}">See them</a>@endif</dd>
                        <dt>Out of stock</dt><dd>{{ $inventory['out'] }} @if ($inventory['out']) <a href="{{ route('seller.products.index', ['stock' => 'out']) }}">See them</a>@endif</dd>
                    </x-ds.dl>
                @else
                    <p class="sd-muted"><strong>No data yet.</strong> Add a product to see its stock here.</p>
                @endif
            </section>
            <section aria-labelledby="a-ret" class="sd-stack">
                <h2 id="a-ret" class="sd-h2">Returns and refunds</h2>
                @if ($returns['delivered'] > 0 || $returns['requested'] > 0 || $returns['refunded_items'] > 0 || $returns['cancelled_items'] > 0)
                    <x-ds.dl>
                        <dt>Delivered or collected</dt><dd>{{ $pl($returns['delivered'], 'item') }}</dd>
                        <dt>Returns asked for</dt><dd>{{ $returns['requested'] }}@if ($returns['rate'] !== null), a return rate of <strong>{{ $pct($returns['rate']) }}</strong>@else, no rate yet because nothing was delivered in this period @endif</dd>
                        <dt>Refunded after delivery</dt><dd>{{ $pl($returns['refunded_items'], 'item') }}@if ($returns['refunded_items']), {{ Shop::money($returns['refunded_cents']) }}@endif</dd>
                        <dt>Cancelled before shipping</dt><dd>{{ $pl($returns['cancelled_items'], 'item') }}</dd>
                    </x-ds.dl>
                @else
                    <p class="sd-muted"><strong>No data yet.</strong> Nothing was delivered, returned or cancelled in this period.</p>
                @endif
            </section>
            <section aria-labelledby="a-ful" class="sd-stack">
                <h2 id="a-ful" class="sd-h2">Fulfilment</h2>
                @if ($fulfilment['due'] > 0 || $fulfilment['shipped'] > 0)
                    <x-ds.dl>
                        <dt>Shipped within your packing time</dt><dd>@if ($fulfilment['rate'] !== null)<strong>{{ $pct($fulfilment['rate']) }}</strong> of {{ $pl($fulfilment['due'], 'parcel') }} that were due @else None due yet @endif</dd>
                        <dt>Payment to shipped</dt><dd>@if ($fulfilment['median_hours'] !== null)<strong>{{ $hrs($fulfilment['median_hours']) }}</strong>, the middle value of {{ $pl($fulfilment['shipped'], 'shipped parcel') }}@else No parcel shipped yet @endif</dd>
                    </x-ds.dl>
                @else
                    <p class="sd-muted"><strong>No data yet.</strong> No parcel was due to ship in this period.</p>
                @endif
            </section>
            <section aria-labelledby="a-rev" class="sd-stack">
                <h2 id="a-rev" class="sd-h2">Reviews</h2>
                @if ($reviews['all_time_count'] > 0)
                    <x-ds.dl>
                        <dt>In this period</dt><dd>@if ($reviews['count'] > 0){{ $pl($reviews['count'], 'review') }}, averaging <strong>{{ number_format($reviews['average'], 1) }}</strong> out of 5 @else No new reviews @endif</dd>
                        <dt>Store rating</dt><dd>@if ($reviews['store_average'] !== null)<strong>{{ number_format($reviews['store_average'], 1) }}</strong> out of 5 from {{ $pl($reviews['all_time_count'], 'review') }} @else Shown to shoppers from {{ $reviews['store_minimum'] }} reviews; you have {{ $reviews['all_time_count'] }} @endif</dd>
                        <dt>Replied to</dt><dd>{{ $pl($reviews['responded'], 'review') }} of {{ $reviews['all_time_count'] }} in all, a reply rate of <strong>{{ $pct($reviews['response_rate']) }}</strong>. <a href="{{ route('seller.feedback', ['tab' => 'reviews']) }}">Reply to reviews</a></dd>
                    </x-ds.dl>
                @else
                    <p class="sd-muted"><strong>No data yet.</strong> Buyers can review a product once it has been delivered.</p>
                @endif
            </section>
        </div>

        <section aria-labelledby="a-defs" class="sd-stack">
            <h2 id="a-defs" class="sd-h2">How each number is counted</h2>
            <dl class="sd-defs">
                <dt>Sales</dt><dd>The value of your items on orders placed in the period that the buyer paid for and that were not cancelled or refunded, after any promo discount. Prices include VAT. Delivery fees are left out. Orders waiting for payment or whose payment failed are never counted.</dd>
                <dt>Orders and items</dt><dd>Orders that hold at least one such item of yours, and the quantity on those items.</dd>
                <dt>Average order</dt><dd>Sales divided by orders. Not shown when there are no orders.</dd>
                <dt>Views and added to cart</dt><dd>People who opened a listing, or added it to a cart, counted once per person per day. Emall keeps a scrambled code for each visitor, never a name. Your own visits to your own listings are not counted.</dd>
                <dt>Orders per view</dt><dd>Orders for a listing divided by its views. Shown only when a listing has views.</dd>
                <dt>Shipped within your packing time</dt><dd>Of the parcels that are not collection, the share shipped within the packing days on the shipping method the buyer chose, counted in working days from payment. A parcel that is not due yet is neither a hit nor a miss.</dd>
                <dt>Return rate</dt><dd>Return requests made in the period divided by items delivered or collected in the period.</dd>
                <dt>Stock value</dt><dd>Stock on hand times your selling price, for options that are switched on, on listings that are approved and switched on.</dd>
            </dl>
        </section>
    </div>
</x-seller-layout>
