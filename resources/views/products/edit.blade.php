<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.3rem;">
        Edit Listing
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1.5rem;">Editing <strong style="color:#a1a1aa;">{{ $product->name }}</strong>. Price/stock changes go live immediately — no re-review needed.</p>

    @if ($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.products.update', $product) }}" style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PATCH')

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Product Name</label>
            <input type="text" name="name" value="{{ old('name', $product->name) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Description</label>
            <textarea name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description', $product->description) }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Price</label>
                <input type="number" step="0.01" name="price" value="{{ old('price', $product->price) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Compare-at Price</label>
                <input type="number" step="0.01" name="compare_price" value="{{ old('compare_price', $product->compare_price) }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Stock</label>
                <input type="number" name="stock" value="{{ old('stock', $product->stock) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">SKU</label>
                <input type="text" name="sku" value="{{ old('sku', $product->sku) }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Category</label>
            <select name="category_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— None —</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" style="padding:0.6rem 1.2rem;border-radius:8px;background:linear-gradient(135deg,#f59e0b,#d97706);color:#1a1a1a;font-weight:700;border:none;cursor:pointer;">
                Save Changes
            </button>
            <a href="{{ route('seller.products.index') }}" style="padding:0.6rem 1.2rem;border-radius:8px;background:transparent;border:1px solid rgba(255,255,255,0.12);color:#a1a1aa;font-weight:600;text-decoration:none;">
                Cancel
            </a>
        </div>
    </form>
</div>
</x-app-layout>
