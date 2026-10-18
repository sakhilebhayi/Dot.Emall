<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:800px;">

    <div style="margin-bottom:1.75rem;">
        <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;letter-spacing:-0.02em;">
            Your Cart
        </div>
        <div style="font-size:0.78rem;color:#71717a;margin-top:0.25rem;">
            {{ $items->count() }} item{{ $items->count() === 1 ? '' : 's' }}
        </div>
    </div>

    @if(session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif
    @if(session('error'))
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('error') }}
        </div>
    @endif

    @if($items->isEmpty())
    <div class="dot-card" style="padding:3rem 2rem;text-align:center;">
        <div style="width:56px;height:56px;border-radius:14px;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <span class="material-symbols-rounded" style="font-size:28px;color:#f59e0b;">shopping_cart</span>
        </div>
        <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.4rem;">Your cart is empty</div>
        <div style="font-size:0.8rem;color:#71717a;margin-bottom:1.25rem;">Browse the marketplace to find something you like.</div>
        <a href="{{ route('products.index') }}" style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.5rem;border-radius:9999px;background:linear-gradient(135deg,#f59e0b,#d97706);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#1a1a1a;text-decoration:none;">
            Browse Products
        </a>
    </div>
    @else
    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;">
        @foreach($items as $item)
        @php $problem = $problems[$item->id] ?? null; @endphp
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;flex-wrap:wrap;{{ !$loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
            <div style="width:52px;height:52px;border-radius:8px;background:linear-gradient(135deg,rgba(245,158,11,0.15),rgba(217,119,6,0.08));display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
                @if($item->product->images->isNotEmpty())
                    <img src="{{ $item->product->images->first()->url() }}" alt="" style="width:100%;height:100%;object-fit:cover;">
                @else
                    <span class="material-symbols-rounded" style="font-size:22px;color:#f59e0b;opacity:0.6;">inventory_2</span>
                @endif
            </div>
            <div style="flex:1;min-width:180px;">
                <a href="{{ route('products.show', $item->product) }}" style="font-size:0.82rem;font-weight:700;color:#f4f4f5;text-decoration:none;">{{ $item->product->name }}</a>
                <div style="font-size:0.7rem;color:#71717a;">{{ $item->product->store->name }} · ${{ number_format($item->product->price, 2) }} each</div>
                @if($problem)
                <div style="font-size:0.72rem;font-weight:600;color:#f59e0b;margin-top:0.25rem;" role="alert">{{ $problem }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('cart.update', $item) }}" style="display:flex;gap:0.4rem;align-items:center;">
                @csrf
                @method('PATCH')
                <label for="qty-{{ $item->id }}" style="position:absolute;left:-9999px;">Quantity of {{ $item->product->name }}</label>
                <input id="qty-{{ $item->id }}" type="number" name="quantity" value="{{ $item->quantity }}" min="1" max="{{ max(1, min(99, $item->product->stock)) }}" class="dot-input" style="width:72px;padding:0.35rem 0.5rem;">
                <button type="submit" class="dot-btn dot-btn-ghost" style="padding:0.35rem 0.7rem;font-size:0.72rem;">Update</button>
            </form>
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;white-space:nowrap;min-width:80px;text-align:right;">
                ${{ number_format($item->quantity * $item->product->price, 2) }}
            </div>
            <form method="POST" action="{{ route('cart.destroy', $item) }}">
                @csrf @method('DELETE')
                <button type="submit" class="topbar-btn" title="Remove {{ $item->product->name }}" aria-label="Remove {{ $item->product->name }}">
                    <span class="material-symbols-rounded" style="font-size:16px;">delete</span>
                </button>
            </form>
        </div>
        @endforeach
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#f4f4f5;">
            <span>Subtotal</span>
            <span>${{ number_format($subtotal, 2) }}</span>
        </div>
    </div>

    <form method="POST" action="{{ route('cart.checkout') }}" class="dot-card" style="padding:1.25rem 1.5rem;">
        @csrf
        <input type="hidden" name="checkout_token" value="{{ $checkoutToken }}">
        <div style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.75rem;">Delivery</div>
        <label for="shipping_name" style="display:block;font-size:0.75rem;font-weight:600;color:#a1a1aa;margin-bottom:0.4rem;">Name for delivery</label>
        <input id="shipping_name" type="text" name="shipping_name" value="{{ old('shipping_name', auth()->user()->name) }}" maxlength="120" class="dot-input" style="margin-bottom:1rem;">
        <label for="shipping_address" style="display:block;font-size:0.75rem;font-weight:600;color:#a1a1aa;margin-bottom:0.4rem;">Shipping address</label>
        <input id="shipping_address" type="text" name="shipping_address" value="{{ old('shipping_address') }}" required placeholder="Street, city, postal code" class="dot-input" style="margin-bottom:1rem;">
        <label for="shipping_phone" style="display:block;font-size:0.75rem;font-weight:600;color:#a1a1aa;margin-bottom:0.4rem;">Phone for the courier (optional)</label>
        <input id="shipping_phone" type="tel" name="shipping_phone" value="{{ old('shipping_phone') }}" maxlength="40" class="dot-input" style="margin-bottom:0.4rem;">
        <div style="font-size:0.68rem;color:#71717a;margin-bottom:1rem;">Sellers see these details only for items they still have to ship.</div>
        <label style="display:block;font-size:0.75rem;font-weight:600;color:#a1a1aa;margin-bottom:0.4rem;">Promo code (optional)</label>
        <input type="text" name="promo_code" placeholder="e.g. SUMMER10" class="dot-input" style="margin-bottom:1rem;text-transform:uppercase;">
        @if(count($problems) > 0)
        <div style="font-size:0.78rem;font-weight:600;color:#f59e0b;text-align:center;" role="alert">Fix the items marked above before placing your order.</div>
        @elseif($paystack['refused'])
        <div style="font-size:0.78rem;font-weight:600;color:#f59e0b;text-align:center;" role="alert">Card payment is not available right now, so orders cannot be placed. Please try again later.</div>
        @elseif($paystack['enabled'])
        <button type="submit" class="dot-btn dot-btn-primary" style="width:100%;justify-content:center;">Pay with Paystack</button>
        @else
        <button type="submit" class="dot-btn dot-btn-primary" style="width:100%;justify-content:center;">Place order</button>
        @endif
        @if($paystack['enabled'])
        <div style="font-size:0.7rem;color:#71717a;margin-top:0.6rem;text-align:center;">
            @if($paystack['testMode'])<span style="display:inline-block;margin-right:0.4rem;padding:0.1rem 0.45rem;border-radius:4px;background:rgba(245,158,11,0.14);color:#f59e0b;font-weight:700;letter-spacing:0.06em;font-size:0.62rem;">TEST MODE</span>No real money moves.@endif
            Placing the order holds your items and takes you to Paystack to pay in {{ $paystack['currency'] }}. An order that is not paid within {{ $paystack['windowMinutes'] }} minutes is cancelled.
        </div>
        @elseif(! $paystack['refused'])
        <div style="font-size:0.7rem;color:#71717a;margin-top:0.6rem;text-align:center;">No payment is taken. Your order is placed as pending and unpaid.</div>
        @endif
    </form>
    @endif

</div>
</x-app-layout>
