{{--
  The seller dashboard (R-51), ONE page for two routes: `dashboard` (the landing) and `seller.dashboard` (the desk home), both fed by App\Services\Dashboard\SellerHome.
  A headline that says what is true now, the one primary action, then two columns of rows: what needs you and the recent orders, beside the sales, the ledger and how the shop is doing.
  Every figure is read for this one store; a figure with no data says "No data yet", never zero. Money is one currency per figure, never summed.
--}}
@php
    use App\Services\Dashboard\Plural;
    use App\Support\Shop;
    $plural = fn (int $n, string $one, ?string $many = null) => Plural::of($n, $one, $many);
    $cur = $ledgerCurrency;
    $bal = $balance[$cur] ?? ['held' => 0, 'released' => 0, 'commission' => 0];
    $hasLedger = ! empty($balance);
    $hours = fn (?float $h) => $h === null ? null : ($h < 1 ? 'under an hour' : ($h < 48 ? round($h).' hours' : round($h / 24, 1).' days'));
    $lowNames = $needs['lowStockSample']->map(fn ($v) => $v->product->name.($v->label() ? ', '.$v->label() : '').' ('.$v->stock.' left)')->implode(', ');
@endphp
<x-seller-layout>
    <x-slot:title>Dashboard</x-slot:title>

    <div class="dx" data-landing="seller" data-dashboard="seller">
        <x-dx.arrive :headline="$headline" :sentence="$sentence" :primary="$primary" art="seller" :first-name="$firstName" :company="$company" role="running" />

        <div class="dx-desk">
            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-needs">
                    <div class="dx-hd"><h2 id="dx-needs">Needs you</h2></div>
                    @if ($needs['total'] === 0)
                        <x-dx.none top>Nothing needs you right now. Orders to ship, returns to answer, low stock, questions, reviews and rejected listings all show up here when they do.</x-dx.none>
                    @else
                        <ul class="dx-rows">
                            @if ($needs['toShip'])<x-dx.row :n="$needs['toShip']" :title="$plural($needs['toShip'], 'parcel').' to pack and ship'" note="Paid and waiting for you." :href="route('seller.orders.index', ['tab' => 'to_ship'])" go="Open orders" />@endif
                            @if ($needs['returns'])<x-dx.row :n="$needs['returns']" :title="$plural($needs['returns'], 'return').' to answer'" :note="$needs['returnsDueDays'] !== null ? ($needs['returnsDueDays'] === 0 ? 'The oldest goes to the Emall team today if unanswered.' : 'The oldest goes to the Emall team in '.$plural($needs['returnsDueDays'], 'day').' if unanswered.') : null" :href="route('seller.returns')" go="Answer returns" />@endif
                            @if ($needs['lowStock'])<x-dx.row :n="$needs['lowStock']" :title="($needs['lowStock'] === 1 ? 'Product' : 'Products').' at or below your low-stock level'" :note="$lowNames.($needs['lowStock'] > 3 ? ' and more' : '')" :href="route('seller.products.index', ['stock' => 'low'])" go="See them" :thumb="$thumbs[$needs['lowStockSample']->first()?->product_id] ?? null" />@endif
                            @if ($needs['questions'])<x-dx.row :n="$needs['questions']" :title="$plural($needs['questions'], 'question').' to answer'" note="Shoppers asked about your products." :href="route('seller.feedback', ['tab' => 'questions'])" go="Answer" />@endif
                            @if ($needs['reviews'])<x-dx.row :n="$needs['reviews']" :title="$plural($needs['reviews'], 'review').' without a reply'" note="You can reply once to each review." :href="route('seller.feedback', ['tab' => 'reviews'])" go="Reply" />@endif
                            @foreach ($needs['rejected'] as $p)<x-dx.row :title="$p->name.' was rejected'" :note="$p->rejected_reason ?: 'No reason was recorded.'" :href="route('seller.products.edit', $p)" go="Fix and resend" :thumb="$thumbs[$p->id] ?? null" />@endforeach
                        </ul>
                    @endif
                    @if ($needs['awaitingPayment'] || $needs['pendingListings'])
                        <p class="dx-note">
                            @if ($needs['awaitingPayment']){{ $plural($needs['awaitingPayment'], 'parcel') }} {{ $needs['awaitingPayment'] === 1 ? 'is' : 'are' }} waiting for the buyer to pay: do not ship {{ $needs['awaitingPayment'] === 1 ? 'it' : 'them' }} yet. @endif
                            @if ($needs['pendingListings']){{ $plural($needs['pendingListings'], 'listing') }} {{ $needs['pendingListings'] === 1 ? 'is' : 'are' }} waiting for the Emall team to review. @endif
                        </p>
                    @endif
                </section>

                <section class="dx-col" aria-labelledby="dx-orders">
                    <div class="dx-hd"><h2 id="dx-orders">Recent orders</h2><a href="{{ route('seller.orders.index') }}">All orders</a></div>
                    @if ($recent->isEmpty())
                        <x-dx.none art="shelf">No orders for your store yet. A parcel appears here the moment a buyer places an order.</x-dx.none>
                    @else
                        @php $showShipTo = $recent->contains(fn ($r) => $r['buyerName'] !== null); @endphp
                        <div class="ds-table-wrap" tabindex="0" role="region" aria-label="Recent orders"><table class="dx-tbl">
                            <caption class="ds-visually-hidden">Recent orders</caption>
                            <thead><tr><th scope="col">Placed</th><th scope="col">Order</th>@if ($showShipTo)<th scope="col">Ship to</th>@endif<th scope="col" class="is-num">Items</th><th scope="col">Status</th><th scope="col" class="is-num">Your items</th></tr></thead>
                            <tbody>
                                @foreach ($recent as $row)
                                    <tr>
                                        <td class="c-p">{{ $row['order']->created_at->format('j M Y') }}</td>
                                        <td class="c-o"><a class="dx-rowlink ds-code" href="{{ route('seller.orders.show', $row['group']) }}">{{ $row['order']->reference() }}</a></td>
                                        @if ($showShipTo)<td class="c-x">{{ $row['buyerName'] ?? 'Sent' }}</td>@endif
                                        <td class="c-x is-num">{{ $row['lineCount'] }}</td>
                                        <td class="c-s">@if ($row['awaitingPayment'])<x-ds.status state="awaiting_payment" />@else<x-ds.status :state="$row['status']->value" />@endif</td>
                                        <td class="c-a is-num"><strong>{{ $row['order']->money($row['netCents']) }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                        @if ($showShipTo)<p class="dx-note">The buyer's name shows while something of theirs is left to pack or send. After dispatch it reads "Sent".</p>@endif
                    @endif
                </section>
            </div>

            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-sales">
                    <div class="dx-hd">
                        <h2 id="dx-sales">Sales</h2>
                        <nav class="dx-period" aria-label="Period">
                            @foreach ($periods as $days => $label)<a href="{{ route('seller.dashboard', ['period' => $days]) }}" @if ($window['days'] === $days) aria-current="true" @endif>{{ [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'][$days] ?? $label }}</a>@endforeach
                        </nav>
                    </div>
                    <div class="dx-fig">
                        <span class="dx-soft">{{ $window['label'] }}. Items after any discount, VAT included, delivery left out.</span>
                        @if ($sales['has_data'])
                            <span class="dx-big" data-money="{{ $sales['sales_cents'] }}">{{ Shop::money($sales['sales_cents']) }}</span>
                            <span class="dx-note">From {{ $plural($sales['orders'], 'order') }} and {{ $plural($sales['units'], 'item') }}.@if ($previous['has_data']) The {{ $window['days'] }} days before: {{ Shop::money($previous['sales_cents']) }} from {{ $plural($previous['orders'], 'order') }}.@endif</span>
                        @else
                            <span class="dx-big dx-big--empty">No data yet</span>
                            <span class="dx-note">No paid orders in this period. Sales appear here once a buyer has paid.</span>
                        @endif
                    </div>
                    <div>
                        <x-dx.chart :chart="$chart" :period="strtolower($window['label'])" />
                    </div>
                    @if ($sales['has_data'])
                        <dl class="dx-facts">
                            <div><dt>Orders</dt><dd>{{ $sales['orders'] }}</dd></div>
                            <div><dt>Items sold</dt><dd>{{ $sales['units'] }}</dd></div>
                            <div><dt>Average order</dt><dd>{{ Shop::money((int) $sales['aov_cents']) }}<small>Items per order</small></dd></div>
                        </dl>
                    @endif
                    @if ($sales['other_currencies'])
                        <div class="dx-col">
                            <p class="dx-note">Sold in other currencies in the same period. Each currency is its own figure: they are never added to the one above.</p>
                            <dl class="dx-facts">
                                @foreach ($sales['other_currencies'] as $code => $other)
                                    <div><dt>{{ $code }}</dt><dd>{{ Shop::money($other['cents'], $code) }}<small>From {{ $plural($other['orders'], 'order') }}.</small></dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                    <p><a href="{{ route('seller.analytics', ['period' => $window['days']]) }}">Open analytics</a></p>
                </section>

                <section class="dx-col" aria-labelledby="dx-ledger">
                    <div class="dx-hd"><h2 id="dx-ledger">Your ledger</h2><a href="{{ route('seller.ledger') }}">Open ledger</a></div>
                    @if ($hasLedger)
                        <dl class="dx-facts">
                            <div><dt>Held, waiting out the return window</dt><dd>{{ Shop::money($bal['held']) }}</dd></div>
                            <div><dt>Released to you</dt><dd>{{ Shop::money($bal['released']) }}</dd></div>
                        </dl>
                        <p class="dx-note">After Emall's fee. Reversals from cancellations and refunds are already taken off. Released earnings are paid out by arrangement with the Emall team: there are no automatic payouts yet.</p>
                    @else
                        <x-dx.none top>No earnings recorded yet. A sale is added the moment the buyer pays.</x-dx.none>
                    @endif
                </section>

                <section class="dx-col" aria-labelledby="dx-doing">
                    <div class="dx-hd"><h2 id="dx-doing">How you are doing</h2></div>
                    <dl class="dx-facts">
                        <div>
                            <dt>Shipped within your packing time</dt>
                            <dd @class(['dx-soft' => $fulfilment['rate'] === null])>
                                @if ($fulfilment['rate'] !== null){{ rtrim(rtrim(number_format($fulfilment['rate'], 1), '0'), '.') }}% of {{ $plural($fulfilment['due'], 'parcel') }} due
                                    <small>@if ($fulfilment['due'] < config('emall.seller.trust_min_sample', 5))That is a small number, so shoppers do not see it until you have {{ config('emall.seller.trust_min_sample', 5) }} orders. @endif Counted from payment, in working days, against the handling time on the shipping method the buyer chose.</small>
                                @else No data yet<small>No parcel was due in this period.</small>@endif
                            </dd>
                        </div>
                        <div><dt>Payment to shipped</dt><dd @class(['dx-soft' => $fulfilment['median_hours'] === null])>@if ($fulfilment['median_hours'] !== null){{ $hours($fulfilment['median_hours']) }}<small>Middle value of {{ $plural($fulfilment['shipped'], 'shipped parcel') }}.</small>@else No data yet @endif</dd></div>
                        <div><dt>Average rating</dt><dd @class(['dx-soft' => $reviews['average'] === null])>@if ($reviews['average'] !== null){{ number_format($reviews['average'], 1) }} out of 5<small>From {{ $plural($reviews['count'], 'review') }} on your products.</small>@else No data yet<small>None of your products has a review.</small>@endif</dd></div>
                        <div><dt>Answering questions</dt><dd @class(['dx-soft' => $responses['questions']['hours'] === null])>@if ($responses['questions']['hours'] !== null){{ $hours($responses['questions']['hours']) }}<small>To a first answer, middle value of {{ $plural($responses['questions']['sample'], 'question') }}.</small>@else No data yet<small>You have not answered a question.</small>@endif</dd></div>
                        <div><dt>Replying to reviews</dt><dd @class(['dx-soft' => $responses['reviews']['hours'] === null])>@if ($responses['reviews']['hours'] !== null){{ $hours($responses['reviews']['hours']) }}<small>To a reply, middle value of {{ $plural($responses['reviews']['sample'], 'review') }}.</small>@else No data yet<small>You have not replied to a review.</small>@endif</dd></div>
                    </dl>
                </section>
            </div>
        </div>

        <x-dx.foot :items="$foot"><a href="{{ route('account.home') }}">Your buyer account</a></x-dx.foot>
    </div>
</x-seller-layout>
