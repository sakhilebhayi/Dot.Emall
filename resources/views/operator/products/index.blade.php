<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">
        Listing Review
    </h1>

    @if (session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $product)
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem;margin-bottom:1rem;">
            <p style="font-size:0.95rem;font-weight:600;color:#f4f4f5;margin:0 0 0.25rem;">{{ $product->name }}</p>
            <p style="font-size:0.78rem;color:#71717a;margin:0 0 0.75rem;">{{ \App\Support\Shop::amount($product->price) }} · {{ $product->store->name }} (owner: {{ $product->store->owner?->name }})</p>

            <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                <form method="POST" action="{{ route('operator.products.approve', $product) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#22c55e;color:#052e13;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve</button>
                </form>
                <form method="POST" action="{{ route('operator.products.reject', $product) }}" style="display:flex;gap:0.4rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for rejecting" required style="padding:0.4rem;border-radius:6px;font-size:0.78rem;">
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#ef4444;color:#3f0d0d;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Reject</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No pending listings.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:2rem 0 1rem;">Recently Reviewed</h2>
    @forelse ($reviewed as $product)
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <p style="font-size:0.9rem;color:#f4f4f5;margin:0;">{{ $product->name }}</p>
                <p style="font-size:0.75rem;color:#71717a;margin:0;">{{ $product->store->name }}</p>
            </div>
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $product->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : 'background:rgba(239,68,68,0.1);color:#f87171;' }}">
                {{ ucfirst($product->status) }}
            </span>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No reviewed listings yet.</p>
    @endforelse
</div>
</x-app-layout>
