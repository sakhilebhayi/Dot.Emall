<nav class="co-helpnav" aria-label="Help topics">
    <a href="{{ route('help.index') }}" @if (request()->routeIs('help.index')) aria-current="page" @endif>Help centre</a>
    <a href="{{ route('help.delivery') }}" @if (request()->routeIs('help.delivery')) aria-current="page" @endif>Delivery</a>
    <a href="{{ route('help.returns') }}" @if (request()->routeIs('help.returns')) aria-current="page" @endif>Returns and refunds</a>
    <a href="{{ route('help.contact') }}" @if (request()->routeIs('help.contact')) aria-current="page" @endif>Contact</a>
    <a href="{{ route('help.sellers') }}" @if (request()->routeIs('help.sellers')) aria-current="page" @endif>Selling</a>
</nav>
