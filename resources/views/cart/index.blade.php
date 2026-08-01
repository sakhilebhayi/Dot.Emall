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
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;{{ !$loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
            <div style="width:52px;height:52px;border-radius:8px;background:linear-gradient(135deg,rgba(245,158,11,0.15),rgba(217,119,6,0.08));display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
                @if($item->product->images->isNotEmpty())
                    <img src="{{ asset('storage/' . $item->product->images->first()->path) }}" alt="" style="width:100%;height:100%;object-fit:cover;">
                @else
                    <span class="material-symbols-rounded" style="font-size:22px;color:#f59e0b;opacity:0.6;">inventory_2</span>
                @endif
            </div>
            <div style="flex:1;min-width:0;">
                <a href="{{ route('products.show', $item->product) }}" style="font-size:0.82rem;font-weight:700;color:#f4f4f5;text-decoration:none;">{{ $item->product->name }}</a>
                <div style="font-size:0.7rem;color:#71717a;">{{ $item->product->store->name }} · Qty {{ $item->quantity }}</div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;white-space:nowrap;">
                ${{ number_format($item->quantity * $item->product->price, 2) }}
            </div>
            <form method="POST" action="{{ route('cart.destroy', $item) }}">
                @csrf @method('DELETE')
                <button type="submit" class="topbar-btn" title="Remove">
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
        <label style="display:block;font-size:0.75rem;font-weight:600;color:#a1a1aa;margin-bottom:0.4rem;">Shipping address</label>
        <input type="text" name="shipping_address" required placeholder="Street, city, postal code" class="dot-input" style="margin-bottom:1rem;">
        <button type="submit" class="dot-btn dot-btn-primary" style="width:100%;justify-content:center;">
            <span class="material-symbols-rounded" style="font-size:16px;">lock</span>
            Place Order
        </button>
    </form>
    @endif

</div>
</x-app-layout>
