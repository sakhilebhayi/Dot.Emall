<x-operator-layout>
    <x-slot:title>Listing review</x-slot:title>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:var(--font-text);font-size:1.5rem;font-weight:800;color:var(--ink);margin:0 0 1.5rem;">
        Listing Review
    </h1>

    @if (session('status'))
        <div style="background:var(--pos-bg);border:1px solid var(--pos);color:var(--pos);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:var(--ink);margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $product)
        <div style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.25rem;margin-bottom:1rem;">
            <p style="font-size:0.95rem;font-weight:600;color:var(--ink);margin:0 0 0.25rem;">{{ $product->name }}</p>
            <p style="font-size:0.78rem;color:var(--ink-3);margin:0 0 0.75rem;">{{ \App\Support\Shop::amount($product->price) }} · {{ $product->store->name }} (owner: {{ $product->store->owner?->name }})</p>

            <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                <form method="POST" action="{{ route('operator.products.approve', $product) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:var(--pos);color:var(--pos-bg);font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve</button>
                </form>
                <form method="POST" action="{{ route('operator.products.reject', $product) }}" style="display:flex;gap:0.4rem;align-items:center;">
                    @csrf
                    <input aria-label="Reason for rejecting" type="text" name="reason" placeholder="Reason for rejecting" required style="padding:0.4rem;border-radius:6px;font-size:0.78rem;">
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:var(--crit);color:var(--crit-bg);font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Reject</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:var(--ink-3);">No pending listings.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:var(--ink);margin:2rem 0 1rem;">Recently Reviewed</h2>
    @forelse ($reviewed as $product)
        <div style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <p style="font-size:0.9rem;color:var(--ink);margin:0;">{{ $product->name }}</p>
                <p style="font-size:0.75rem;color:var(--ink-3);margin:0;">{{ $product->store->name }}</p>
            </div>
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $product->status === 'approved' ? 'background:var(--pos-bg);color:var(--pos);' : 'background:var(--crit-bg);color:var(--crit);' }}">
                {{ ucfirst($product->status) }}
            </span>
        </div>
    @empty
        <p style="font-size:0.8rem;color:var(--ink-3);">No reviewed listings yet.</p>
    @endforelse
</div>
</x-operator-layout>
