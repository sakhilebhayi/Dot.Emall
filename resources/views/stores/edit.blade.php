<x-seller-layout>
    <x-slot:title>Store profile</x-slot:title>
<div style="padding:2rem 2.5rem 3rem;max-width:560px;">
    <h1 style="font-family:var(--font-text);font-size:1.5rem;font-weight:800;color:var(--ink);margin:0 0 0.3rem;">My store</h1>
    <p style="font-size:0.78rem;color:var(--ink-3);margin:0 0 1.5rem;">Public address: <span style="color:var(--ink-2);">{{ $store->slug }}</span>. It stays the same if you rename the store. Orders already placed keep the name they were bought under.</p>

    @if (session('status'))
        <div style="background:var(--pos-bg);border:1px solid var(--pos);color:var(--pos);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div style="background:var(--crit-bg);border:1px solid var(--crit);color:var(--crit);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.store.update') }}" style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PATCH')

        <div>
            <label for="store-name" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Store name</label>
            <input id="store-name" type="text" name="name" value="{{ old('name', $store->name) }}" required maxlength="255" class="dot-input">
        </div>

        <div>
            <label for="store-description" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Description</label>
            <textarea id="store-description" name="description" rows="4" maxlength="2000" class="dot-input">{{ old('description', $store->description) }}</textarea>
        </div>

        <label style="display:flex;gap:0.6rem;align-items:flex-start;font-size:0.8rem;color:var(--ink);cursor:pointer;">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $store->is_active)) style="margin-top:0.2rem;">
            <span>Open for business
                <span style="display:block;font-size:0.7rem;color:var(--ink-3);">Untick to hide every listing from the marketplace without deleting anything. Orders already placed carry on as normal.</span>
            </span>
        </label>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" class="dot-btn dot-btn-primary">Save store</button>
            <a href="{{ route('dashboard') }}" class="dot-btn dot-btn-ghost">Cancel</a>
        </div>
    </form>
</div>
</x-seller-layout>
