{{--
  The buyer's dashboard (R-51), ONE page for two routes: `dashboard` (the landing, for a person with no store) and `account.home` (the account overview), both fed by App\Services\Dashboard\BuyerHome.
  A headline that says what is true now, the one primary action, then your orders and the newest listings beside what needs you, what you saved and your account. Every figure is this person's own record.
--}}
@php
    use App\Services\Dashboard\Plural;
    use App\Support\Shop;
    $needsRows = $awaiting > 0 || $returnsNeedingYou->isNotEmpty() || $isOperator && $operatorTotal > 0;
@endphp
<x-account-layout>
    <x-slot:title>{{ request()->routeIs('account.home') ? 'Your account' : 'Welcome' }}</x-slot:title>

    <div class="dx" data-landing="buyer" data-dashboard="buyer">
        <x-dx.arrive :headline="$headline" :sentence="$sentence" :primary="$primary" art="buyer" :first-name="$firstName" :company="$company" role="for" />

        <div class="dx-desk">
            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-orders">
                    <div class="dx-hd"><h2 id="dx-orders">Your orders</h2>@if ($orders->isNotEmpty())<a href="{{ route('orders.index') }}">All orders</a>@endif</div>
                    @if ($orders->isEmpty())
                        <x-dx.none top>You have not ordered anything yet. An order shows up here as soon as you place it.</x-dx.none>
                    @else
                        <div class="ds-table-wrap" tabindex="0" role="region" aria-label="Recent orders"><table class="dx-tbl">
                            <caption class="ds-visually-hidden">Recent orders</caption>
                            <thead><tr><th scope="col">Order</th><th scope="col">Placed</th><th scope="col" class="is-num">Items</th><th scope="col" class="is-num">Total</th><th scope="col">Status</th></tr></thead>
                            <tbody>
                                @foreach ($orders as $o)
                                    <tr>
                                        <td class="c-o"><a class="dx-rowlink ds-code" href="{{ route('orders.show', $o) }}">{{ $o->reference() }}</a></td>
                                        <td class="c-p">{{ $o->created_at->format('j M Y') }}</td>
                                        <td class="c-x is-num">{{ $o->items->count() }}</td>
                                        <td class="c-a is-num"><strong>{{ $o->money($o->totalCents()) }}</strong></td>
                                        <td class="c-s"><x-ds.status kind="order" :state="$statuses[$o->id]" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>

                <section class="dx-col" aria-labelledby="dx-shelf">
                    <div class="dx-hd">
                        <h2 id="dx-shelf">{{ $open ? 'New on the shelves' : 'The mall today' }}</h2>
                        <a href="{{ $open ? route('products.index') : route('products.index') }}">{{ $open ? 'Shop the mall' : 'Look at the mall' }}</a>
                    </div>
                    @if ($open)
                        <ul class="dx-rows">
                            @foreach ($shelf as $p)
                                <x-dx.row :title="$p->name" :note="$p->store?->name" :href="route('products.show', $p)" :thumb="$p->images->first()?->cardUrl()" :class="$p->images->isEmpty() ? 'dx-row--nophoto' : null">
                                    <x-slot:aside><span class="dx-price">{{ Shop::amount($p->price) }}</span></x-slot:aside>
                                </x-dx.row>
                            @endforeach
                        </ul>
                        <p class="dx-note">The newest listings in the mall, at the price each seller set.</p>
                    @else
                        <x-dx.none art="shelf" class="dx-hatch">Nothing is listed yet. Products appear here once a shop has listed them. Until then there is nothing to buy.</x-dx.none>
                    @endif
                </section>
            </div>

            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-needs">
                    <div class="dx-hd"><h2 id="dx-needs">Needs you</h2></div>
                    @if (! $needsRows)
                        <x-dx.none top>Nothing needs you. Unpaid orders and returns that need an answer from you show up here.@if ($openReturns > 0) {{ Plural::of($openReturns, 'return') }} {{ Plural::verb($openReturns) }} open and nothing is asked of you yet.@endif</x-dx.none>
                    @else
                        <ul class="dx-rows">
                            @if ($awaiting > 0)<x-dx.row :n="$awaiting" :title="$awaiting === 1 ? 'Order waiting for payment' : 'Orders waiting for payment'" note="Pay before it is cancelled and the items go back on sale." :href="route('orders.index', ['status' => 'awaiting_payment'])" go="See {{ $awaiting === 1 ? 'it' : 'them' }}" />@endif
                            @foreach ($returnsNeedingYou as $r)<x-dx.row :title="($r->item?->displayName() ?? 'A return')" :note="$r->status === \App\Enums\ReturnStatus::Declined ? 'Declined: read why.' : 'Approved: send it back.'" :href="route('account.returns.show', $r)" go="Open" />@endforeach
                            @if ($isOperator && $operatorTotal > 0)<x-dx.row :n="$operatorTotal" title="Operator desk" note="Listings, stores and payments that need a decision." :href="route('operator.home')" go="Open the desk" />@endif
                        </ul>
                        @if ($openReturns > $returnsNeedingYou->count())<p class="dx-note">{{ Plural::of($openReturns, 'return') }} {{ Plural::verb($openReturns) }} open in all.</p>@endif
                    @endif
                </section>

                <section class="dx-col" aria-labelledby="dx-wish">
                    <div class="dx-hd"><h2 id="dx-wish">Saved for later</h2><a href="{{ emall_route('wishlist.index') }}" {!! emall_pending('wishlist.index') !!}>Wishlist</a></div>
                    @if ($wishlist->isEmpty())
                        <x-dx.none top>Your wishlist is empty. Save things from a product page to find them here.</x-dx.none>
                    @else
                        <ul class="dx-rows">
                            @foreach ($wishlist as $w)
                                @if ($w->product)
                                    <x-dx.row :title="$w->product->name" :href="route('products.show', $w->product)" compact :thumb="$w->product->images->first()?->cardUrl()" class="dx-row--wish">
                                        <x-slot:aside><span class="dx-price">{{ Shop::amount($w->product->price) }}</span></x-slot:aside>
                                    </x-dx.row>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="dx-col" aria-labelledby="dx-account">
                    <div class="dx-hd"><h2 id="dx-account">Your account</h2>@if ($sells)<a href="{{ route('seller.dashboard') }}">Open your seller desk</a>@endif</div>
                    <dl class="dx-facts">
                        <div>
                            <dt>Delivery address</dt>
                            @if ($defaultAddress)
                                <dd>{{ $defaultAddress->recipient_name }}<small>Default of {{ Plural::of($addressCount, 'saved address', 'saved addresses') }}.@if ($defaultAddress->phone) {{ $defaultAddress->phone }}@endif <a href="{{ route('account.addresses') }}">Address book</a></small></dd>
                            @else
                                <dd class="dx-soft">None saved<small>Add one and checkout becomes one press. <a href="{{ route('account.addresses') }}">Add an address</a></small></dd>
                            @endif
                        </div>
                        <div><dt>Alerts you set</dt><dd><a href="{{ emall_route('account.alerts') }}" {!! emall_pending('account.alerts') !!}>{{ $alerts }} active</a><small>Back in stock, price drops</small></dd></div>
                        <div><dt>Saved searches</dt><dd><a href="{{ emall_route('account.saved-searches') }}" {!! emall_pending('account.saved-searches') !!}>{{ $searches }} saved</a></dd></div>
                        @if ($unread > 0)<div><dt>Unread notifications</dt><dd>{{ $unread }}</dd></div>@endif
                        <div><dt>What we e-mail you</dt><dd><a href="{{ route('account.notifications') }}">Notification choices</a></dd></div>
                        <div><dt>Your data</dt><dd><a href="{{ route('account.privacy') }}">Download or delete</a></dd></div>
                        @unless ($sells)<div><dt>Sell on Dot.Emall</dt><dd><a href="{{ route('sell.start') }}">Open a shop</a><small>Sell to every buyer in the mall{{ $company ? ' as '.$company : '' }}.</small></dd></div>@endunless
                    </dl>
                </section>
            </div>
        </div>

        <x-dx.foot :items="$foot" />
    </div>
</x-account-layout>
