<x-seller-layout>
    <x-slot:title>Promotions</x-slot:title>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:var(--font-text);font-size:1.5rem;font-weight:800;color:var(--ink);margin:0 0 0.3rem;">
        Promo Codes
    </h1>
    <p style="font-size:0.78rem;color:var(--ink-3);margin:0 0 1.5rem;">Discount codes for <strong style="color:var(--ink-2);">{{ $store->name }}</strong>'s items only.</p>

    @if (session('status'))
        <div style="background:var(--pos-bg);border:1px solid var(--pos);color:var(--pos);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            {{ session('status') }}
        </div>
    @endif
    @if ($errors->any())
        <div style="background:var(--crit-bg);border:1px solid var(--crit);color:var(--crit);padding:0.75rem 1rem;border-radius:8px;font-size:0.8rem;margin-bottom:1.5rem;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('seller.promo-codes.store') }}" style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1.5rem;display:flex;flex-direction:column;gap:1rem;margin-bottom:2rem;">
        @csrf
        <div>
            <label for="promo-codes-index-code" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Code</label>
            <input id="promo-codes-index-code" type="text" name="code" value="{{ old('code') }}" required maxlength="40" placeholder="SUMMER10" style="width:100%;padding:0.5rem;border-radius:6px;text-transform:uppercase;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="promo-codes-index-discount-type" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Discount Type</label>
                <select id="promo-codes-index-discount-type" name="discount_type" style="width:100%;padding:0.5rem;border-radius:6px;">
                    <option value="percent" @selected(old('discount_type') === 'percent')>Percent off</option>
                    <option value="fixed" @selected(old('discount_type') === 'fixed')>Fixed amount off</option>
                </select>
            </div>
            <div>
                <label for="promo-codes-index-discount-value" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Discount Value</label>
                <input id="promo-codes-index-discount-value" type="number" step="0.01" name="discount_value" value="{{ old('discount_value') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="promo-codes-index-max-uses" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Max Uses (optional)</label>
                <input id="promo-codes-index-max-uses" type="number" name="max_uses" value="{{ old('max_uses') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="promo-codes-index-expires-at" style="display:block;font-size:0.75rem;color:var(--ink-2);margin-bottom:0.25rem;">Expires (optional)</label>
                <input id="promo-codes-index-expires-at" type="date" name="expires_at" value="{{ old('expires_at') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>
        <button type="submit" style="padding:0.6rem;border-radius:8px;background:var(--brand);color:var(--on-brand);font-weight:700;border:none;cursor:pointer;">
            Create Code
        </button>
    </form>

    @forelse ($promoCodes as $promoCode)
    <div style="background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
        <div>
            <div style="font-family:'DM Mono',monospace;font-size:0.9rem;font-weight:700;color:var(--ink);">{{ $promoCode->code }}</div>
            <div style="font-size:0.75rem;color:var(--ink-3);">
                {{ $promoCode->discount_type === 'percent' ? number_format($promoCode->discount_value, 0).'% off' : \App\Support\Shop::amount($promoCode->discount_value).' off' }}
                · {{ $promoCode->used_count }}{{ $promoCode->max_uses ? '/'.$promoCode->max_uses : '' }} used
                @if($promoCode->expires_at) · expires {{ $promoCode->expires_at->format('d M Y') }} @endif
                @unless($promoCode->isRedeemable()) · <span style="color:var(--crit);">inactive</span> @endunless
            </div>
        </div>
        <form method="POST" action="{{ route('seller.promo-codes.destroy', $promoCode) }}" data-confirm="Delete this code?">
            @csrf
            @method('DELETE')
            <button type="submit" style="padding:0.35rem 0.8rem;border-radius:6px;background:var(--crit-bg);color:var(--crit);font-size:0.72rem;font-weight:600;border:none;cursor:pointer;">Delete</button>
        </form>
    </div>
    @empty
    <p style="font-size:0.8rem;color:var(--ink-3);">No promo codes yet.</p>
    @endforelse
</div>
</x-seller-layout>
