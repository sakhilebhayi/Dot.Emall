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
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Price ({{ \App\Support\Shop::currency() }})</label>
                <input type="number" step="0.01" name="price" value="{{ old('price', $product->price) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Compare-at Price ({{ \App\Support\Shop::currency() }})</label>
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

    {{-- Photos --}}
    @php $photoCount = $product->images->count(); $room = max(0, \App\Services\ProductImages::MAX_PER_PRODUCT - $photoCount); @endphp
    <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.5rem;margin-top:1.5rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.75rem;">
            <h2 style="margin:0;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Photos</h2>
            <span style="font-size:0.72rem;color:#71717a;">{{ $photoCount }} of {{ \App\Services\ProductImages::MAX_PER_PRODUCT }}</span>
        </div>

        @if($photoCount === 0)
        <p style="font-size:0.78rem;color:#71717a;margin:0 0 1rem;">No photos yet. Listings with photos are easier to trust.</p>
        @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:0.75rem;margin-bottom:1rem;">
            @foreach($product->images as $image)
            <div style="border:1px solid rgba(255,255,255,0.08);border-radius:10px;overflow:hidden;background:#0d0d10;">
                <img src="{{ $image->url() }}" alt="Photo {{ $loop->iteration }} of {{ $product->name }}" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block;">
                <div style="padding:0.4rem 0.5rem;display:flex;flex-direction:column;gap:0.3rem;">
                    @if($loop->first)
                    <span style="font-size:0.65rem;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.06em;">Cover</span>
                    @else
                    <form method="POST" action="{{ route('seller.products.images.cover', [$product, $image]) }}">
                        @csrf @method('PATCH')
                        <button type="submit" style="background:none;border:none;padding:0;font-size:0.7rem;color:#a1a1aa;cursor:pointer;text-decoration:underline;">Make cover</button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('seller.products.images.destroy', [$product, $image]) }}" onsubmit="return confirm('Remove this photo?');">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;padding:0;font-size:0.7rem;color:#f87171;cursor:pointer;text-decoration:underline;">Remove</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        @if($room > 0)
        <form method="POST" action="{{ route('seller.products.images.store', $product) }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:0.6rem;">
            @csrf
            <label for="photo-upload" style="font-size:0.75rem;color:#a1a1aa;">Add photos ({{ $room }} more allowed)</label>
            <input id="photo-upload" type="file" name="images[]" multiple required accept="image/jpeg,image/png,image/webp" class="dot-input">
            <div style="font-size:0.7rem;color:#71717a;">JPEG, PNG or WebP, up to 4 MB each. Large photos are resized and location data is removed.</div>
            <div><button type="submit" class="dot-btn dot-btn-ghost">Upload</button></div>
        </form>
        @else
        <p style="font-size:0.75rem;color:#71717a;margin:0;">This listing has the maximum of {{ \App\Services\ProductImages::MAX_PER_PRODUCT }} photos. Remove one to add another.</p>
        @endif
    </div>
</div>
</x-app-layout>
