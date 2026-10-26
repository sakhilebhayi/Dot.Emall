<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:560px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">
        Become a Seller
    </h1>

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.store.store') }}" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label for="stores-create-name" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Store Name</label>
            <input id="stores-create-name" type="text" name="name" value="{{ old('name') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label for="stores-create-description" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea id="stores-create-description" name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description') }}</textarea>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;border:none;cursor:pointer;">
            Create My Store
        </button>
    </form>
</div>
</x-app-layout>
