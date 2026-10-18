<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:1000px;">

    <a href="{{ route('products.index') }}" style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.75rem;color:#71717a;text-decoration:none;margin-bottom:1.25rem;">
        <span class="material-symbols-rounded" style="font-size:15px;">arrow_back</span>
        Back to marketplace
    </a>

    @if(session('status'))
        <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#22c55e;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#ef4444;padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.25rem;">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div style="display:grid;grid-template-columns:340px 1fr;gap:2rem;margin-bottom:2.5rem;">
        <div x-data="{ current: @js($product->images->first()?->url()) }">
            <div style="width:100%;aspect-ratio:1/1;border-radius:12px;background:linear-gradient(135deg,rgba(245,158,11,0.15),rgba(217,119,6,0.08));display:flex;align-items:center;justify-content:center;overflow:hidden;">
                @if($product->images->isNotEmpty())
                    <img :src="current" src="{{ $product->images->first()->url() }}" alt="{{ $product->name }}" style="width:100%;height:100%;object-fit:cover;">
                @else
                    <span class="material-symbols-rounded" style="font-size:56px;color:#f59e0b;opacity:0.6;">inventory_2</span>
                @endif
            </div>
            @if($product->images->count() > 1)
            <div style="display:flex;gap:0.5rem;margin-top:0.6rem;flex-wrap:wrap;">
                @foreach($product->images as $image)
                <a href="{{ $image->url() }}" @click.prevent="current = '{{ $image->url() }}'" aria-label="Show photo {{ $loop->iteration }} of {{ $product->images->count() }}" style="width:56px;height:56px;border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,0.1);display:block;">
                    <img src="{{ $image->url() }}" alt="" style="width:100%;height:100%;object-fit:cover;">
                </a>
                @endforeach
            </div>
            @endif
        </div>

        <div>
            @if($product->category)
            <div style="font-size:0.68rem;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.4rem;">{{ $product->category->name }}</div>
            @endif
            <h1 style="margin:0 0 0.4rem;font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;">{{ $product->name }}</h1>
            <div style="font-size:0.8rem;color:#71717a;margin-bottom:1rem;">Sold by <strong style="color:#a1a1aa;">{{ $product->store->name }}</strong></div>

            <div style="display:flex;align-items:baseline;gap:0.6rem;margin-bottom:1rem;">
                <span style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f59e0b;">{{ \App\Support\Shop::amount($product->price) }}</span>
                @if($product->compare_price && $product->compare_price > $product->price)
                <span style="font-size:0.9rem;color:#52525b;text-decoration:line-through;">{{ \App\Support\Shop::amount($product->compare_price) }}</span>
                @endif
            </div>

            @if($product->rating_count > 0)
            <div style="display:flex;align-items:center;gap:0.3rem;margin-bottom:1.25rem;">
                <span class="material-symbols-rounded" style="font-size:16px;color:#facc15;">star</span>
                <span style="font-size:0.8rem;font-weight:700;color:#f4f4f5;">{{ number_format($product->rating_average, 1) }}</span>
                <span style="font-size:0.75rem;color:#71717a;">({{ $product->rating_count }} review{{ $product->rating_count === 1 ? '' : 's' }})</span>
            </div>
            @endif

            @if($product->description)
            <p style="font-size:0.85rem;color:#a1a1aa;line-height:1.6;margin-bottom:1.5rem;">{{ $product->description }}</p>
            @endif

            <div style="font-size:0.75rem;color:{{ $product->stock > 0 ? '#22c55e' : '#ef4444' }};margin-bottom:1rem;">
                {{ $product->stock > 0 ? "{$product->stock} in stock" : 'Out of stock' }}
            </div>

            @guest
                @if($product->stock > 0)
                <a href="{{ route('login') }}" class="dot-btn dot-btn-primary">
                    <span class="material-symbols-rounded" style="font-size:16px;">login</span>
                    Sign in to buy
                </a>
                <div style="font-size:0.7rem;color:#71717a;margin-top:0.5rem;">No account yet? <a href="{{ route('register') }}" style="color:#f59e0b;">Create one</a>. You come back to this page afterwards.</div>
                @endif
            @endguest
            @auth
                @if($product->is_active && $product->store->is_active && $product->stock > 0 && $product->store->owner_id !== auth()->id())
                <form method="POST" action="{{ route('cart.store') }}" style="display:flex;gap:0.75rem;align-items:center;">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <input type="number" name="quantity" value="1" min="1" max="{{ min(99, $product->stock) }}" class="dot-input" style="width:80px;">
                    <button type="submit" class="dot-btn dot-btn-primary">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_shopping_cart</span>
                        Add to Cart
                    </button>
                </form>
                @endif
            @endauth
        </div>
    </div>

    {{-- ─── Reviews ──────────────────────────────────────────────────────── --}}
    <div>
        <h2 style="margin:0 0 1rem;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Reviews</h2>

        @auth
            @if($canReview)
            <form method="POST" action="{{ route('reviews.store', $product) }}" class="dot-card" style="padding:1.25rem;margin-bottom:1.5rem;">
                @csrf
                <div style="font-size:0.8rem;font-weight:600;color:#f4f4f5;margin-bottom:0.6rem;">Leave a review</div>
                <select name="rating" class="dot-input" style="max-width:140px;margin-bottom:0.6rem;" required>
                    <option value="">Rating…</option>
                    @for($i = 5; $i >= 1; $i--)
                        <option value="{{ $i }}">{{ $i }} star{{ $i === 1 ? '' : 's' }}</option>
                    @endfor
                </select>
                <textarea name="body" rows="2" placeholder="Optional comments…" class="dot-input" style="margin-bottom:0.75rem;resize:vertical;"></textarea>
                <button type="submit" class="dot-btn dot-btn-primary">Submit Review</button>
            </form>
            @elseif(!$hasReviewed)
            <div style="font-size:0.75rem;color:#71717a;margin-bottom:1.25rem;">Reviews come from buyers: you can review this product after an order containing it has been delivered.</div>
            @endif
        @endauth

        @if($product->reviews->isEmpty())
        <div class="dot-card" style="padding:2rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:30px;color:#71717a;display:block;margin-bottom:0.5rem;">rate_review</span>
            <div style="font-size:0.8rem;color:#71717a;">No reviews yet.</div>
        </div>
        @else
        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            @foreach($product->reviews as $review)
            <div class="dot-card" style="padding:1rem 1.25rem;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.3rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;">
                        <span style="font-size:0.78rem;font-weight:700;color:#f4f4f5;">{{ $review->user?->publicName() ?? 'Buyer' }}</span>
                        <span style="display:flex;">
                            @for($i = 1; $i <= 5; $i++)
                                <span class="material-symbols-rounded" style="font-size:13px;color:{{ $i <= $review->rating ? '#facc15' : '#3f3f46' }};">star</span>
                            @endfor
                        </span>
                    </div>
                    @if(auth()->id() === $review->user_id)
                    <form method="POST" action="{{ route('reviews.destroy', $review) }}" onsubmit="return confirm('Delete this review?');">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:#71717a;cursor:pointer;font-size:0.7rem;">Delete</button>
                    </form>
                    @endif
                </div>
                @if($review->body)
                <p style="margin:0;font-size:0.78rem;color:#a1a1aa;line-height:1.5;">{{ $review->body }}</p>
                @endif
            </div>
            @endforeach
        </div>
        @endif
    </div>

</div>
</x-app-layout>
