<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:560px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.3rem;">My store</h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1.5rem;">Public address: <span style="color:#a1a1aa;">{{ $store->slug }}</span>. It stays the same if you rename the store. Orders already placed keep the name they were bought under.</p>

    @if (session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.store.update') }}" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PATCH')

        <div>
            <label for="store-name" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Store name</label>
            <input id="store-name" type="text" name="name" value="{{ old('name', $store->name) }}" required maxlength="255" class="dot-input">
        </div>

        <div>
            <label for="store-description" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea id="store-description" name="description" rows="4" maxlength="2000" class="dot-input">{{ old('description', $store->description) }}</textarea>
        </div>

        <label style="display:flex;gap:0.6rem;align-items:flex-start;font-size:0.8rem;color:#d4d4d8;cursor:pointer;">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $store->is_active)) style="margin-top:0.2rem;">
            <span>Open for business
                <span style="display:block;font-size:0.7rem;color:#71717a;">Untick to hide every listing from the marketplace without deleting anything. Orders already placed carry on as normal.</span>
            </span>
        </label>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save store</button>
            <a href="{{ route('dashboard') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
</x-app-layout>
