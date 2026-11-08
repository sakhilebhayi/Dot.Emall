@php use App\Support\Shop; @endphp
<x-account-layout>
    <x-slot:title>Your account</x-slot:title>
    <x-slot:heading>Hello, {{ $user->name }}</x-slot:heading>
    <x-slot:lead>Your orders, returns and saved things in one place. Accounts come from InfoDot.</x-slot:lead>
    <x-slot:actions>
        @if ($sells)<x-ds.button :href="route('seller.dashboard')" variant="quiet" icon="store">Open your seller desk</x-ds.button>@endif
    </x-slot:actions>

    @if ($awaiting > 0)
        <x-ds.alert tone="cau" title="{{ $awaiting }} {{ $awaiting === 1 ? 'order is' : 'orders are' }} waiting for payment" class="co-gap">Unpaid orders are cancelled after a short time and the items go back on sale. <a href="{{ route('orders.index', ['status' => 'awaiting_payment']) }}">See them</a>.</x-ds.alert>
    @endif

    <x-ds.section title="Recent orders">
        <x-slot:actions><a href="{{ route('orders.index') }}">All orders</a></x-slot:actions>
        @if ($orders->isEmpty())
            <x-ds.empty-state title="No orders yet" icon="receipt">When you buy something, it shows up here.<x-slot:actions><x-ds.button :href="route('products.index')">Start shopping</x-ds.button></x-slot:actions></x-ds.empty-state>
        @else
            <ul class="co-plain">
                @foreach ($orders as $o)
                    <li class="co-row">
                        <span><a href="{{ route('orders.show', $o) }}" class="ds-code">{{ $o->reference() }}</a> <span class="ds-muted">{{ $o->created_at->format('j M Y') }}, {{ $o->items->count() }} {{ $o->items->count() === 1 ? 'item' : 'items' }}, {{ $o->money($o->totalCents()) }}</span></span>
                        <x-ds.status kind="order" :state="$statuses[$o->id]" />
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ds.section>

    <div class="co-grid2">
        <x-ds.section title="Returns">
            <x-slot:actions><a href="{{ route('account.returns') }}">All returns</a></x-slot:actions>
            @if ($returnsNeedingYou->isEmpty())
                <p class="ds-muted">{{ $openReturns > 0 ? $openReturns.' '.($openReturns === 1 ? 'return is' : 'returns are').' open and nothing needs you right now.' : 'Nothing to do. Returns can be asked for within '.(int) config('emall.returns.window_days', 7).' days of delivery.' }}</p>
            @else
                <ul class="co-plain">
                    @foreach ($returnsNeedingYou as $r)
                        <li class="co-row"><span>{{ $r->item?->displayName() }}</span><a href="{{ route('account.returns.show', $r) }}">{{ $r->status === \App\Enums\ReturnStatus::Declined ? 'Declined: read why' : 'Approved: send it back' }}</a></li>
                    @endforeach
                </ul>
            @endif
        </x-ds.section>

        <x-ds.section title="Delivery address">
            <x-slot:actions><a href="{{ route('account.addresses') }}">Address book</a></x-slot:actions>
            @if ($defaultAddress)
                <x-ds.address-block :name="$defaultAddress->recipient_name" :lines="[\App\Models\Address::oneLine($defaultAddress->snapshot())]" :phone="$defaultAddress->phone" :is-default="true" />
                <p class="ds-muted">{{ $addressCount }} saved {{ $addressCount === 1 ? 'address' : 'addresses' }}.</p>
            @else
                <p class="ds-muted">No saved address yet. Add one and checkout becomes one press.</p>
                <x-ds.button :href="route('account.addresses')" variant="quiet" size="sm" icon="plus">Add an address</x-ds.button>
            @endif
        </x-ds.section>
    </div>

    <x-ds.section title="Saved for later">
        <x-slot:actions><a href="{{ emall_route('wishlist.index') }}" {!! emall_pending('wishlist.index') !!}>Wishlist</a></x-slot:actions>
        @if ($wishlist->isEmpty())
            <p class="ds-muted">Your wishlist is empty. Save things from a product page to find them here.</p>
        @else
            <div class="ds-grid">
                @foreach ($wishlist as $w)
                    @if ($w->product)<x-ds.product-card :href="route('products.show', $w->product)" :title="$w->product->name" :image="$w->product->images->first()?->url()" :store="$w->product->store?->name" :amount="$w->product->price" :stock="$w->product->stock" ratio="1 / 1" />@endif
                @endforeach
            </div>
        @endif
    </x-ds.section>

    <x-ds.section title="Keeping track">
        <ul class="co-plain">
            <li class="co-row"><span>Alerts you set (back in stock, price drops)</span><a href="{{ emall_route('account.alerts') }}" {!! emall_pending('account.alerts') !!}>{{ $alerts }} active</a></li>
            <li class="co-row"><span>Saved searches</span><a href="{{ emall_route('account.saved-searches') }}" {!! emall_pending('account.saved-searches') !!}>{{ $searches }} saved</a></li>
            <li class="co-row"><span>Unread notifications</span><span>{{ $unread }}</span></li>
            <li class="co-row"><span>What we e-mail you</span><a href="{{ route('account.notifications') }}">Notification choices</a></li>
            <li class="co-row"><span>Your data</span><a href="{{ route('account.privacy') }}">Download or delete</a></li>
        </ul>
    </x-ds.section>
</x-account-layout>
