{{-- Alerts the person asked for (R-43 W4). Data: App\Http\Controllers\ProductAlertController::index(). --}}
<x-account-layout>
    <x-slot:title>Alerts</x-slot:title>
    <x-slot:heading>Alerts</x-slot:heading>
    <x-slot:lead>Each alert is one thing you asked to be told about one product. It sends once, and you can cancel it here.</x-slot:lead>

    <p class="pdp-fact" style="margin-block-end: var(--s-5);">Alerts arrive in the app. To get them by e-mail as well, <a href="{{ $preferencesUrl }}">choose your delivery channels in your settings</a>.</p>

    @if ($active->isEmpty())
        <x-ds.empty-state title="No active alerts" icon="bell">Set a price or stock alert from a product page or your wishlist.
            <x-slot:actions><x-ds.button :href="route('wishlist.index')" variant="quiet">Open your wishlist</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @else
        <x-ds.table caption="Active alerts">
            <x-slot:head><tr><th scope="col">Product</th><th scope="col">Alert</th><th scope="col">Set when the price was</th><th scope="col"><span class="ds-visually-hidden">Action</span></th></tr></x-slot:head>
            @foreach ($active as $alert)
                @php($v = $alert->variant_id ? $variants->get($alert->variant_id) : null)
                <tr>
                    <td data-label="Product"><a href="{{ route('products.show', ['product' => $alert->product_id] + ($v ? ['variant' => $v->id] : [])) }}">{{ $alert->product?->name }}</a>@if ($v?->label()) <span class="ds-muted">({{ $v->label() }})</span>@endif</td>
                    <td data-label="Alert">{{ $alert->kind === 'price_drop' ? 'Price drops'.($alert->target_price ? ' to '.\App\Support\Shop::money(\App\Support\Money::toCents($alert->target_price)).' or less' : '') : 'Back in stock' }}</td>
                    <td data-label="Price then" class="is-num">{{ $alert->baseline_price !== null ? \App\Support\Shop::money(\App\Support\Money::toCents($alert->baseline_price)) : 'Not recorded' }}</td>
                    <td><form method="POST" action="{{ route('alerts.destroy', $alert) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="quiet" size="sm">Cancel alert</x-ds.button></form></td>
                </tr>
            @endforeach
        </x-ds.table>
    @endif

    @if ($past->isNotEmpty())
        <h2 class="pdp-sub" style="margin-block-start: var(--s-6);">Already sent</h2>
        <ul class="wl-list">
            @foreach ($past as $alert)
                <li class="wl-item"><div class="wl-item__body" style="grid-column: 1 / -1;"><p class="wl-item__title"><a href="{{ route('products.show', $alert->product_id) }}">{{ $alert->product?->name }}</a></p><p class="ds-muted">{{ $alert->kind === 'price_drop' ? 'Price alert' : 'Stock alert' }} sent {{ $alert->triggered_at?->format('j M Y') }}.</p></div></li>
            @endforeach
        </ul>
    @endif
</x-account-layout>
