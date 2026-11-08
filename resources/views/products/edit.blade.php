<x-seller-layout>
    <x-slot:title>Edit product</x-slot:title>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:var(--font-text);font-size:1.5rem;font-weight:800;color:var(--ink);margin:0 0 0.3rem;">
        Edit Listing
    </h1>
    <p style="font-size:0.78rem;color:var(--ink-3);margin:0 0 1.5rem;">Editing <strong style="color:var(--ink-2);">{{ $product->name }}</strong>. Price/stock changes go live immediately — no re-review needed.</p>

    @if ($errors->any())
        <div style="background:var(--crit-bg);border:1px solid var(--crit);color:var(--crit);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.products.update', $product) }}" style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PATCH')

        <div>
            <label for="products-edit-name" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Product Name</label>
            <input id="products-edit-name" type="text" name="name" value="{{ old('name', $product->name) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label for="products-edit-description" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Description</label>
            <textarea id="products-edit-description" name="description" rows="4" style="width:100%;padding:0.5rem;border-radius:6px;">{{ old('description', $product->description) }}</textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="products-edit-price" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Price ({{ \App\Support\Shop::currency() }})</label>
                <input id="products-edit-price" type="number" step="0.01" name="price" value="{{ old('price', $product->price) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="products-edit-compare-price" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Compare-at Price ({{ \App\Support\Shop::currency() }})</label>
                <input id="products-edit-compare-price" type="number" step="0.01" name="compare_price" value="{{ old('compare_price', $product->compare_price) }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="products-edit-stock" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Stock</label>
                <input id="products-edit-stock" type="number" name="stock" value="{{ old('stock', $product->stock) }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="products-edit-sku" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">SKU</label>
                <input id="products-edit-sku" type="text" name="sku" value="{{ old('sku', $product->sku) }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <div>
            <label for="products-edit-category-id" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Category</label>
            <select id="products-edit-category-id" name="category_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">None</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $product->category_id) == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
            <button type="submit" style="padding:0.6rem 1.2rem;border-radius:8px;background:var(--brand);color:var(--on-brand);font-weight:700;border:none;cursor:pointer;">
                Save Changes
            </button>
            <a href="{{ route('seller.products.index') }}" style="padding:0.6rem 1.2rem;border-radius:8px;background:transparent;border:1px solid var(--line);color:var(--ink-2);font-weight:600;text-decoration:none;">
                Cancel
            </a>
        </div>
    </form>

    {{-- Photos --}}
    @php $photoCount = $product->images->count(); $room = max(0, \App\Services\ProductImages::MAX_PER_PRODUCT - $photoCount); @endphp
    <div style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.5rem;margin-top:1.5rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.75rem;">
            <h2 style="margin:0;font-family:var(--font-text);font-size:1rem;font-weight:700;color:var(--ink);">Photos</h2>
            <span style="font-size:0.72rem;color:var(--ink-3);">{{ $photoCount }} of {{ \App\Services\ProductImages::MAX_PER_PRODUCT }}</span>
        </div>

        @if($photoCount === 0)
        <p style="font-size:0.78rem;color:var(--ink-3);margin:0 0 1rem;">No photos yet. Listings with photos are easier to trust.</p>
        @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:0.75rem;margin-bottom:1rem;">
            @foreach($product->images as $image)
            <div style="border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--surface);">
                <img src="{{ $image->url() }}" alt="Photo {{ $loop->iteration }} of {{ $product->name }}" style="width:100%;aspect-ratio:1/1;object-fit:cover;display:block;">
                <div style="padding:0.4rem 0.5rem;display:flex;flex-direction:column;gap:0.3rem;">
                    @if($loop->first)
                    <span style="font-size:0.65rem;font-weight:700;color:var(--brand);text-transform:uppercase;letter-spacing:0.06em;">Cover</span>
                    @else
                    <form method="POST" action="{{ route('seller.products.images.cover', [$product, $image]) }}">
                        @csrf @method('PATCH')
                        <button type="submit" style="background:none;border:none;padding:0;font-size:0.7rem;color:var(--ink-2);cursor:pointer;text-decoration:underline;">Make cover</button>
                    </form>
                    @endif
                    <form method="POST" action="{{ route('seller.products.images.destroy', [$product, $image]) }}" data-confirm="Remove this photo?">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;padding:0;font-size:0.7rem;color:var(--crit);cursor:pointer;text-decoration:underline;">Remove</button>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
        @endif

        @if($room > 0)
        <form method="POST" action="{{ route('seller.products.images.store', $product) }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:0.6rem;">
            @csrf
            <label for="photo-upload" style="font-size:0.75rem;color:var(--ink-2);">Add photos ({{ $room }} more allowed)</label>
            <input id="photo-upload" type="file" name="images[]" multiple required accept="image/jpeg,image/png,image/webp" class="dot-input">
            <div style="font-size:0.7rem;color:var(--ink-3);">JPEG, PNG or WebP, up to 4 MB each. Large photos are resized and location data is removed.</div>
            <div><button type="submit" class="dot-btn dot-btn-ghost">Upload</button></div>
        </form>
        @else
        <p style="font-size:0.75rem;color:var(--ink-3);margin:0;">This listing has the maximum of {{ \App\Services\ProductImages::MAX_PER_PRODUCT }} photos. Remove one to add another.</p>
        @endif
    </div>
</div>
</x-seller-layout>
