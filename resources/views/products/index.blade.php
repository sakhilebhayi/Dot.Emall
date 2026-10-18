<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.75rem;">
        <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;letter-spacing:-0.02em;">
            Browse Products
        </div>
        <div style="font-size:0.78rem;color:#71717a;margin-top:0.25rem;">
            {{ $products->total() }} product{{ $products->total() === 1 ? '' : 's' }} across the marketplace
        </div>
    </div>

    @if(session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif

    {{-- Search + filters --}}
    <form method="GET" action="{{ route('products.index') }}" style="display:flex;gap:0.75rem;margin-bottom:1.75rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:220px;position:relative;">
            <span class="material-symbols-rounded" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:17px;color:#52525b;">search</span>
            <input type="text" name="q" value="{{ $search }}" placeholder="Search products by name…" class="dot-input" style="padding-left:36px;">
        </div>
        <select name="category" class="dot-input" style="max-width:220px;" onchange="this.form.submit()">
            <option value="">All categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" @selected((string) $category === (string) $cat->id)>{{ $cat->name }} ({{ $cat->products_count }})</option>
            @endforeach
        </select>
        <button type="submit" class="dot-btn dot-btn-primary">Search</button>
    </form>

    {{-- Results grid --}}
    @if($products->count())
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1.1rem;margin-bottom:2rem;">
        @foreach($products as $product)
        <a href="{{ route('products.show', $product) }}" class="dot-card" style="display:block;padding:1.1rem;text-decoration:none;transition:border-color .15s;">
            <div style="width:100%;aspect-ratio:1/1;border-radius:8px;background:linear-gradient(135deg,rgba(245,158,11,0.15),rgba(217,119,6,0.08));display:flex;align-items:center;justify-content:center;margin-bottom:0.85rem;overflow:hidden;">
                @if($product->images->isNotEmpty())
                    <img src="{{ $product->images->first()->url() }}" alt="{{ $product->name }}" style="width:100%;height:100%;object-fit:cover;">
                @else
                    <span class="material-symbols-rounded" style="font-size:34px;color:#f59e0b;opacity:0.6;">inventory_2</span>
                @endif
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:0.2rem;">
                {{ $product->name }}
            </div>
            <div style="font-size:0.68rem;color:#71717a;margin-bottom:0.5rem;">{{ $product->store->name }}</div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-family:'Syne',sans-serif;font-size:0.9rem;font-weight:800;color:#f59e0b;">${{ number_format($product->price, 2) }}</span>
                @if($product->rating_count > 0)
                <span style="display:flex;align-items:center;gap:0.2rem;font-size:0.68rem;color:#a1a1aa;">
                    <span class="material-symbols-rounded" style="font-size:13px;color:#facc15;">star</span>
                    {{ number_format($product->rating_average, 1) }} ({{ $product->rating_count }})
                </span>
                @else
                <span style="font-size:0.65rem;color:#52525b;">No reviews yet</span>
                @endif
            </div>
        </a>
        @endforeach
    </div>
    <div>{{ $products->links() }}</div>
    @else
    <div class="dot-card" style="padding:3rem 2rem;text-align:center;">
        <div style="width:56px;height:56px;border-radius:14px;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
            <span class="material-symbols-rounded" style="font-size:28px;color:#f59e0b;">search_off</span>
        </div>
        <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.4rem;">No products found</div>
        <div style="font-size:0.8rem;color:#71717a;">Try a different search term or category.</div>
    </div>
    @endif

</div>
</x-app-layout>
