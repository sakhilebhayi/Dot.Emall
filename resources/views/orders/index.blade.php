<x-account-layout>
    <x-slot:title>Your orders</x-slot:title>
    <x-slot:heading>Your orders</x-slot:heading>
    <x-slot:lead>Everything you have bought, newest first.</x-slot:lead>

    <form method="GET" action="{{ route('orders.index') }}" class="co-filter" role="search">
        <x-ds.input label="Find an order" name="q" id="order-q" :value="$q" placeholder="Order number or product name" autocomplete="off" />
        <x-ds.select label="Show" name="status" id="order-status" :options="$filters" :selected="$filter" />
        <x-ds.button type="submit" variant="quiet" icon="search">Filter</x-ds.button>
        @if ($q !== '' || $filter !== 'all')<x-ds.button :href="route('orders.index')" variant="link">Clear</x-ds.button>@endif
    </form>

    @if ($orders->isEmpty())
        <x-ds.empty-state title="{{ $q !== '' || $filter !== 'all' ? 'No orders match' : 'No orders yet' }}" icon="receipt">
            {{ $q !== '' || $filter !== 'all' ? 'Try a different order number, or show all orders.' : 'When you buy something, it appears here with its delivery progress.' }}
            <x-slot:actions><x-ds.button :href="route('products.index')">Continue shopping</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @else
        <x-ds.table caption="Your orders">
            <x-slot:head><tr><th scope="col">Order</th><th scope="col">Placed</th><th scope="col">Items</th><th scope="col">Status</th><th scope="col" class="is-num">Total</th><th scope="col"><span class="ds-visually-hidden">Actions</span></th></tr></x-slot:head>
            @foreach ($orders as $order)
                <tr>
                    <td data-label="Order"><a href="{{ route('orders.show', $order) }}" class="ds-code">{{ $order->reference() }}</a></td>
                    <td data-label="Placed">{{ $order->created_at->format('j M Y') }}</td>
                    <td data-label="Items">{{ $order->items->count() }} from {{ max(1, $order->groups->count()) }} {{ $order->groups->count() > 1 ? 'sellers' : 'seller' }}</td>
                    <td data-label="Status"><x-ds.status kind="order" :state="$statuses[$order->id]" /></td>
                    <td data-label="Total" class="is-num"><span class="co-total">{{ $order->money($order->totalCents()) }}</span></td>
                    <td data-label="">
                        <div class="co-rowactions">
                            <x-ds.button :href="route('orders.show', $order)" size="sm" variant="quiet">Open<span class="ds-visually-hidden"> order {{ $order->reference() }}</span></x-ds.button>
                            <form method="POST" action="{{ route('orders.reorder', $order) }}">@csrf<x-ds.button type="submit" size="sm" variant="link" icon="cart">Buy again<span class="ds-visually-hidden"> order {{ $order->reference() }}</span></x-ds.button></form>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ds.table>
        <x-ds.pagination :paginator="$orders" />
    @endif
</x-account-layout>
