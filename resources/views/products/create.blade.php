<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.3rem;">
        List a Product
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1.5rem;">Selling from <strong style="color:#a1a1aa;">{{ $store->name }}</strong>. New listings are reviewed before appearing in the marketplace.</p>

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.products.store') }}" enctype="multipart/form-data" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Product Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description') }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Price ({{ \App\Support\Shop::currency() }})</label>
                <input type="number" step="0.01" name="price" value="{{ old('price') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Compare-at Price ({{ \App\Support\Shop::currency() }})</label>
                <input type="number" step="0.01" name="compare_price" value="{{ old('compare_price') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Stock</label>
                <input type="number" name="stock" value="{{ old('stock') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">SKU</label>
                <input type="text" name="sku" value="{{ old('sku') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Category</label>
            <select name="category_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— None —</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="images" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Photos (optional)</label>
            <input id="images" type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp" class="dot-input">
            <div style="font-size:0.7rem;color:#71717a;margin-top:0.3rem;">Up to 6 JPEG, PNG or WebP files, 4 MB each. The first is the cover; you can change that later. Location data in photos is removed.</div>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;border:none;cursor:pointer;">
            Submit for Review
        </button>
    </form>
</div>
</x-app-layout>
