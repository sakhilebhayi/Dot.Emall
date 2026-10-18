<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0;">
            Your Listings
        </h1>
        <a href="{{ route('seller.products.create') }}" style="padding:0.5rem 1rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;font-size:0.78rem;text-decoration:none;">
            + New Listing
        </a>
    </div>

    @if (session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif

    @if(! $store)
        <p style="font-size:0.8rem;color:#71717a;">
            You don't have a store yet.
            <a href="{{ route('seller.store.create') }}" style="color:#f59e0b;">Open one first</a>.
        </p>
    @elseif($products->isEmpty())
        <p style="font-size:0.8rem;color:#71717a;">No listings yet. Create your first one above.</p>
    @else
        @foreach ($products as $product)
        @php
            $statusBadge = match($product->status) {
                'approved' => ['bg' => 'rgba(34,197,94,0.1)', 'color' => '#4ade80'],
                'rejected' => ['bg' => 'rgba(239,68,68,0.1)', 'color' => '#f87171'],
                default    => ['bg' => 'rgba(245,158,11,0.1)', 'color' => '#f59e0b'],
            };
        @endphp
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.1rem 1.25rem;margin-bottom:0.85rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div style="min-width:200px;">
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.2rem;">
                    <span style="font-size:0.9rem;font-weight:700;color:#f4f4f5;">{{ $product->name }}</span>
                    <span style="font-size:10px;font-weight:600;padding:2px 9px;border-radius:100px;background:{{ $statusBadge['bg'] }};color:{{ $statusBadge['color'] }};">{{ ucfirst($product->status) }}</span>
                    @unless($product->is_active)
                    <span style="font-size:10px;font-weight:600;padding:2px 9px;border-radius:100px;background:rgba(107,114,128,0.15);color:#9ca3af;">Deactivated</span>
                    @endunless
                </div>
                <div style="font-size:0.75rem;color:#71717a;">${{ number_format($product->price, 2) }} · {{ $product->stock }} in stock</div>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <a href="{{ route('seller.products.edit', $product) }}" style="padding:0.4rem 0.9rem;border-radius:6px;background:rgba(255,255,255,0.06);color:#e4e4e7;font-size:0.75rem;font-weight:600;text-decoration:none;">Edit</a>
                <form method="POST" action="{{ route('seller.products.toggle-active', $product) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" style="padding:0.4rem 0.9rem;border-radius:6px;background:rgba(255,255,255,0.06);color:{{ $product->is_active ? '#f87171' : '#4ade80' }};font-size:0.75rem;font-weight:600;border:none;cursor:pointer;">
                        {{ $product->is_active ? 'Deactivate' : 'Reactivate' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('seller.products.destroy', $product) }}" onsubmit="return confirm('Delete this listing? It leaves the marketplace for good. Orders already placed keep their history.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="padding:0.4rem 0.9rem;border-radius:6px;background:rgba(255,255,255,0.06);color:#f87171;font-size:0.75rem;font-weight:600;border:none;cursor:pointer;">Delete</button>
                </form>
            </div>
        </div>
        @endforeach
    @endif
</div>
</x-app-layout>
