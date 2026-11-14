<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => 'Packing slip '.$order->reference(), 'description' => null, 'robots' => 'noindex', 'dotUi' => false])
</head>
<body>
    <main id="main" class="sd-slip" tabindex="-1">
        <div class="sd-noprint sd-actions">
            <button type="button" class="ds-btn ds-btn--primary" data-print>Print this slip</button>
            <a class="ds-btn ds-btn--link" href="{{ route('seller.orders.show', $group) }}">Back to the order</a>
        </div>
        <header>
            <h1 style="font-size: var(--text-xl);">Packing slip</h1>
            <p>{{ $store->name }}<br>Order {{ $order->reference() }}, placed {{ $order->created_at->format('j M Y') }}</p>
        </header>
        <section aria-labelledby="slip-to">
            <h2 id="slip-to" class="sd-h3">Send to</h2>
            @if ($address)
                <x-ds.address-block :name="$address['name']" :lines="$address['lines']" :phone="$address['phone']" />
            @elseif ($group->isCollection())
                <p>Collection by the buyer.</p>
            @else
                <p>The delivery address is no longer shown because nothing is left to ship.</p>
            @endif
        </section>
        <section aria-labelledby="slip-items">
            <h2 id="slip-items" class="sd-h3">Items</h2>
            <table>
                <thead><tr><th scope="col">Item</th><th scope="col">SKU</th><th scope="col" style="text-align: end;">Quantity</th></tr></thead>
                <tbody>@foreach ($items as $l)<tr><td>{{ $l->displayName() }}</td><td class="sd-code">{{ $l->sku }}</td><td style="text-align: end;">{{ $l->quantity }}</td></tr>@endforeach</tbody>
            </table>
        </section>
        <p class="sd-quiet">Delivery: {{ $group->method_name ?: 'arranged with the seller' }}. Prices are not shown on this slip.</p>
    </main>
</body>
</html>
