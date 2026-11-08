<x-seller-layout>
    <x-slot:title>Your products</x-slot:title>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;">
        <h1 style="font-family:var(--font-text);font-size:1.5rem;font-weight:800;color:var(--ink);margin:0;">
            Your Listings
        </h1>
        <a href="{{ route('seller.products.create') }}" style="padding:0.5rem 1rem;border-radius:8px;background:var(--brand);color:var(--on-brand);font-weight:700;font-size:0.78rem;text-decoration:none;">
            + New Listing
        </a>
    </div>

    @if (session('status'))
        <div style="background:var(--pos-bg);border:1px solid var(--pos);color:var(--pos);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif

    @if(! $store)
        <p style="font-size:0.8rem;color:var(--ink-3);">
            You don't have a store yet.
            <a href="{{ route('seller.store.create') }}" style="color:var(--brand);">Open one first</a>.
        </p>
    @elseif($products->isEmpty())
        <p style="font-size:0.8rem;color:var(--ink-3);">No listings yet. Create your first one above.</p>
    @else
        @foreach ($products as $product)
        @php
            $statusBadge = match($product->status) {
                'approved' => ['bg' => 'var(--pos)', 'color' => 'var(--pos)'],
                'rejected' => ['bg' => 'var(--crit)', 'color' => 'var(--crit)'],
                default    => ['bg' => 'var(--brand)', 'color' => 'var(--brand)'],
            };
        @endphp
        <div style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.1rem 1.25rem;margin-bottom:0.85rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div style="min-width:200px;">
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.2rem;">
                    <span style="font-size:0.9rem;font-weight:700;color:var(--ink);">{{ $product->name }}</span>
                    <span style="font-size:10px;font-weight:600;padding:2px 9px;border-radius:100px;background:{{ $statusBadge['bg'] }};color:{{ $statusBadge['color'] }};">{{ ucfirst($product->status) }}</span>
                    @unless($product->is_active)
                    <span style="font-size:10px;font-weight:600;padding:2px 9px;border-radius:100px;background:var(--sunken);color:var(--ink-3);">Deactivated</span>
                    @endunless
                </div>
                <div style="font-size:0.75rem;color:var(--ink-3);">{{ \App\Support\Shop::amount($product->price) }} · {{ $product->stock }} in stock</div>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <a href="{{ route('seller.products.edit', $product) }}" style="padding:0.4rem 0.9rem;border-radius:6px;background:var(--sunken);color:var(--ink);font-size:0.75rem;font-weight:600;text-decoration:none;">Edit</a>
                <form method="POST" action="{{ route('seller.products.toggle-active', $product) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" style="padding:0.4rem 0.9rem;border-radius:6px;background:var(--sunken);color:{{ $product->is_active ? 'var(--crit)' : 'var(--pos)' }};font-size:0.75rem;font-weight:600;border:none;cursor:pointer;">
                        {{ $product->is_active ? 'Deactivate' : 'Reactivate' }}
                    </button>
                </form>
                <form method="POST" action="{{ route('seller.products.destroy', $product) }}" data-confirm="Delete this listing? It leaves the marketplace for good. Orders already placed keep their history.">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="padding:0.4rem 0.9rem;border-radius:6px;background:var(--sunken);color:var(--crit);font-size:0.75rem;font-weight:600;border:none;cursor:pointer;">Delete</button>
                </form>
            </div>
        </div>
        @endforeach
    @endif
</div>
</x-seller-layout>
