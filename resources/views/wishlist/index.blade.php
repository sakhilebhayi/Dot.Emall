{{-- The wishlist (R-43 W4). Data: App\Http\Controllers\WishlistController::index(). --}}
<x-account-layout>
    <x-slot:title>Wishlist</x-slot:title>
    <x-slot:heading>Wishlist</x-slot:heading>
    <x-slot:lead>Things you saved. Only you see this list unless you switch sharing on.</x-slot:lead>

    <div class="wl-share" id="share">
        <h2 class="ds-panel__title">Share this list</h2>
        @if ($shareUrl)
            <p>Anyone with this link can see the products on your list. They cannot see your name, your notes or anything else, and you can switch it off.</p>
            <div class="wl-share__row">
                <input class="ds-input" id="share-url" type="text" readonly value="{{ $shareUrl }}" aria-label="Share link" data-select-on-focus>
                <x-ds.button type="button" variant="quiet" icon="copy" data-copy="share-url" data-js-only hidden>Copy link</x-ds.button>
            </div>
            <form method="POST" action="{{ route('wishlist.sharing') }}">@csrf <input type="hidden" name="share" value="0"><x-ds.button type="submit" variant="quiet" size="sm">Turn sharing off</x-ds.button></form>
        @else
            <p>Sharing is off. Turn it on to get a link you can send to someone. Turning it off later kills that link.</p>
            <form method="POST" action="{{ route('wishlist.sharing') }}">@csrf <input type="hidden" name="share" value="1"><x-ds.button type="submit" variant="quiet" size="sm" icon="external">Turn sharing on</x-ds.button></form>
        @endif
    </div>

    @if ($items->isEmpty())
        <x-ds.empty-state title="Your wishlist is empty" icon="heart">Tap the heart on a product to keep it here. We can also tell you when something comes back in stock or drops in price.
            <x-slot:actions><x-ds.button :href="route('products.index')">Browse products</x-ds.button></x-slot:actions>
        </x-ds.empty-state>
    @else
        <ul class="wl-list">
            @foreach ($items as $item)
                @php
                    $p = $item->product;
                    $v = $item->variant_id ? $variants->get($item->variant_id) : null;
                    $mine = $alerts->get($item->product_id, collect());
                    $drop = $mine->first(fn ($a) => $a->kind === 'price_drop' && (int) $a->variant_key === (int) ($item->variant_id ?? 0));
                    $back = $mine->first(fn ($a) => $a->kind === 'back_in_stock' && (int) $a->variant_key === (int) ($item->variant_id ?? 0));
                    $now = \App\Support\Money::toCents($v?->price ?? $p->price);
                    $then = $item->price_when_added !== null ? \App\Support\Money::toCents($item->price_when_added) : null;
                    $link = route('products.show', ['product' => $p, 'src' => 'wishlist'] + ($v ? ['variant' => $v->id] : []));
                @endphp
                <li class="wl-item @unless ($item->available) is-gone @endunless">
                    <a class="wl-item__media" href="{{ $link }}" tabindex="-1" aria-hidden="true">
                        @if ($p->images->first())<img src="{{ $p->images->first()->url() }}" alt="" width="112" height="112" loading="lazy">@endif
                    </a>
                    <div class="wl-item__body">
                        <p class="wl-item__title"><a href="{{ $link }}">{{ $p->name }}</a></p>
                        <p class="ds-muted">{{ $p->store?->name }}@if ($v?->label()), {{ $v->label() }}@endif</p>
                        <x-ds.price :cents="$now" size="md" />
                        @if ($then !== null && $then !== $now)
                            <p class="pdp-fact">You saved it at {{ \App\Support\Shop::money($then) }}. {{ $now < $then ? 'It is cheaper now.' : 'It costs more now.' }}</p>
                        @endif
                        @if (! $item->available)
                            @php($state = $p->deleted_at || $p->status !== 'approved' || ! $p->is_active || ! $p->store?->is_active ? 'unlisted' : 'out_of_stock')
                            <x-ds.status kind="product" :state="$state" />
                        @elseif ($p->stock > 0 && $p->stock <= 5)
                            <x-ds.status kind="product" state="low_stock" :quantity="$p->stock" />
                        @endif
                        @if ($item->note)<p class="ds-muted">Note: {{ $item->note }}</p>@endif
                    </div>
                    <div class="wl-item__actions">
                        @if ($item->available)
                            <form method="POST" action="{{ route('wishlist.items.cart', $item) }}">@csrf <x-ds.button type="submit" size="sm" icon="cart" block>Move to cart</x-ds.button></form>
                        @endif
                        <form method="POST" action="{{ route('wishlist.items.alert', $item) }}">@csrf
                            <input type="hidden" name="kind" value="price_drop"><input type="hidden" name="on" value="{{ $drop ? 0 : 1 }}">
                            <x-ds.button type="submit" variant="quiet" size="sm" icon="bell" block aria-pressed="{{ $drop ? 'true' : 'false' }}">{{ $drop ? 'Price alert on' : 'Tell me if the price drops' }}</x-ds.button>
                        </form>
                        @unless ($item->available)
                            <form method="POST" action="{{ route('wishlist.items.alert', $item) }}">@csrf
                                <input type="hidden" name="kind" value="back_in_stock"><input type="hidden" name="on" value="{{ $back ? 0 : 1 }}">
                                <x-ds.button type="submit" variant="quiet" size="sm" icon="bell" block aria-pressed="{{ $back ? 'true' : 'false' }}">{{ $back ? 'Stock alert on' : 'Tell me when it is back' }}</x-ds.button>
                            </form>
                        @endunless
                        <form method="POST" action="{{ route('wishlist.items.destroy', $item) }}">@csrf @method('DELETE')<x-ds.button type="submit" variant="link" size="sm">Remove</x-ds.button></form>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="ds-muted pdp-note">Alerts arrive in the app. You can also get them by e-mail in your <a href="{{ \App\Support\Shell::first(['account.notifications', 'account.preferences', 'notifications.preferences', 'profile.show'])['url'] }}">notification settings</a>. <a href="{{ route('account.alerts') }}">See all your alerts</a>.</p>
    @endif
</x-account-layout>
