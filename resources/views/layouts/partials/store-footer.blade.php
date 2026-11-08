{{-- The public footer: real links (help, returns, legal, sell), and one honest sentence about accounts. Variable: $viewer. --}}
<footer class="ds-footer">
    <div class="ds-container">
        <div class="ds-footer__grid">
            <div class="ds-footer__about">
                <x-ds.logo :href="url('/')" />
                <p>Dot.Emall is a marketplace where South African sellers list real products and shoppers see the total price, delivery included, before they pay.</p>
                @unless ($viewer['user'])
                    <p>Accounts come from InfoDot. Sign in with your InfoDot account to buy, sell and follow your orders.</p>
                    <div><x-ds.button :href="route('login')" variant="quiet" size="sm">Sign in with InfoDot</x-ds.button></div>
                @endunless
            </div>
            @foreach (\App\Support\Navigation::footer() as $col)
                <nav aria-label="{{ $col['title'] }}">
                    <h2>{{ $col['title'] }}</h2>
                    <ul>
                        @foreach ($col['links'] as $l)
                            @php($r = \App\Support\Shell::first(array_merge([$l['route']], $l['alt'] ?? [])))
                            <li><a href="{{ $r['url'] }}" @if ($r['url'] === '#') data-pending-route="{{ $r['name'] }}" @endif>{{ $l['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach
        </div>
        <div class="ds-footer__base">
            <span>&copy; {{ now()->year }} Dot.Emall, part of the Dot ecosystem.</span>
            <span><a href="https://infodot.co.za">InfoDot</a></span>
        </div>
    </div>
</footer>
