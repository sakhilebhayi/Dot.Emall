@php
    use App\Support\Shop;
    $guest = $mode === 'guest';
    $money = fn (int $c) => $order->money($c);
    $snap = $access['shipping']['details'] ?? null;
@endphp
<x-dynamic-component :component="$guest ? 'store-layout' : 'account-layout'" :robots="'noindex'">
    <x-slot:title>Receipt for order {{ $order->reference() }}</x-slot:title>
    <x-slot:heading>Receipt for order {{ $order->reference() }}</x-slot:heading>
    <x-slot:lead>A record of what was ordered and paid. It is a receipt, not a VAT tax invoice: none is issued yet.</x-slot:lead>
    <x-slot:actions>
        <x-ds.button :href="$guest ? route('guest.orders.show', $order) : route('orders.show', $order)" variant="quiet" icon="arrow-left">Back to the order</x-ds.button>
        <x-ds.button variant="primary" icon="receipt" data-print>Print this receipt</x-ds.button>
    </x-slot:actions>

    <article class="co-receipt">
        <dl class="ds-dl">
            <dt>Order</dt><dd><span class="ds-code">{{ $order->reference() }}</span></dd>
            <dt>Placed</dt><dd>{{ $order->created_at->format('j F Y, H:i') }}</dd>
            <dt>Buyer</dt><dd>{{ $order->shipping_name ?: ($order->user?->name ?? $order->guest_email) }}, {{ $order->buyerEmail() }}</dd>
            @if ($snap)<dt>Delivered to</dt><dd>{{ \App\Models\Address::oneLine($snap) }}</dd>@elseif ($order->shipping_address)<dt>Delivered to</dt><dd>{{ $order->shipping_address }}</dd>@endif
            <dt>Payment</dt>
            <dd>
                @if (! $order->requires_payment) No payment was taken.
                @elseif ($payment['paid']) Paid {{ $payment['paid']->paid_at?->format('j M Y, H:i') }} on Paystack{{ $payment['channel'] ? ' ('.str_replace('_', ' ', $payment['channel']).')' : '' }}, reference <span class="ds-code">{{ $payment['paid']->reference }}</span>.
                @else Not paid.
                @endif
            </dd>
        </dl>

        @foreach ($parcels as $parcel)
            <h2>{{ $parcel['store_name'] }}</h2>
            <table class="ds-table ds-table--stack">
                <caption class="ds-visually-hidden">Items from {{ $parcel['store_name'] }}</caption>
                <thead><tr><th scope="col">Item</th><th scope="col" class="is-num">Quantity</th><th scope="col" class="is-num">Price each</th><th scope="col" class="is-num">Line total</th></tr></thead>
                <tbody>
                @foreach ($parcel['lines'] as $l)
                    <tr @if ($l['cancelled']) class="is-cancelled" @endif>
                        <td data-label="Item">{{ $l['name'] }}@if ($l['cancelled']) <span class="ds-muted">(cancelled)</span>@endif</td>
                        <td data-label="Quantity" class="is-num">{{ $l['item']->quantity }}</td>
                        <td data-label="Price each" class="is-num">{{ $order->amount($l['item']->unit_price) }}</td>
                        <td data-label="Line total" class="is-num">{{ $order->amount($l['item']->total) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if ($parcel['group'])<p>Delivery, {{ $parcel['method'] }}: {{ $parcel['delivery_cents'] === 0 ? 'Free' : $money($parcel['delivery_cents']) }}.</p>@endif
        @endforeach

        <dl class="co-money co-money--receipt">
            <div><dt>Items</dt><dd>{{ $money($totals['subtotal']) }}</dd></div>
            @if ($totals['discount'] > 0)<div><dt>Discount {{ $order->promo_code }}</dt><dd>{{ $money(-$totals['discount']) }}</dd></div>@endif
            <div><dt>Delivery</dt><dd>{{ $money($totals['delivery']) }}</dd></div>
            <div class="co-money__total"><dt>Total {{ $order->payment_status->isSettled() ? 'paid' : '' }}</dt><dd>{{ $money($totals['total']) }}</dd></div>
            @if ($totals['vat'] > 0)<div><dt>VAT included</dt><dd>{{ $money($totals['vat']) }}</dd></div>@endif
        </dl>
        @if ($refunds->isNotEmpty())
            <h2>Refunds</h2>
            <ul class="co-list">@foreach ($refunds as $refund)<li>{{ Shop::money($refund->amount_cents, $refund->currency) }}, {{ str_replace('refund_', '', $refund->status->value) }}{{ $refund->processed_at ? ', '.$refund->processed_at->format('j M Y') : '' }}</li>@endforeach</ul>
        @endif
    </article>
</x-dynamic-component>
