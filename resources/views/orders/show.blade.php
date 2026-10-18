@php
    use App\Enums\OrderStatus;
    use App\Services\OrderAccess;

    $role = $view['role'];
    $lines = $view['lines'];
    $isSeller = $role === OrderAccess::SELLER;
    $isBuyer = $role === OrderAccess::BUYER;
    $isOperator = $role === OrderAccess::OPERATOR;
    $shownStatus = $view['fullOrder'] ? $order->status : OrderStatus::derive($lines->pluck('status'));
    $backRoute = $isSeller ? route('seller.orders.index') : route('orders.index');
    $backLabel = $isSeller ? 'Back to orders to fulfil' : 'Back to my orders';
@endphp
<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:800px;">

    <a href="{{ $backRoute }}" style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.75rem;color:#71717a;text-decoration:none;margin-bottom:1.25rem;">
        <span class="material-symbols-rounded" style="font-size:15px;">arrow_back</span>
        {{ $backLabel }}
    </a>

    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:0.6rem;flex-wrap:wrap;">
        <div>
            <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:#f4f4f5;">
                Order {{ $order->reference() }}
            </div>
            <div style="font-size:0.78rem;color:#71717a;margin-top:0.2rem;">Placed {{ $order->created_at->format('d M Y, H:i') }}@if($isSeller) · showing your items only @endif</div>
        </div>
        <x-status-pill :status="$shownStatus" />
    </div>

    @unless($isSeller)
    <div style="font-size:0.75rem;color:#a1a1aa;margin-bottom:1.5rem;">
        Payment is not collected on Dot.Emall yet. This order is <strong style="color:#f4f4f5;">pending and unpaid</strong>.
    </div>
    @else
    <div style="margin-bottom:1.5rem;"></div>
    @endunless

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">
        {{ session('status') }}
    </div>
    @endif
    @error('status')
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
        {{ $message }}
    </div>
    @enderror

    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;">
        @foreach($lines as $item)
        @php
            $cancelled = $item->isCancelled();
            $canCancel = $item->status->isCancellable() && ($isBuyer || ($isSeller && auth()->id() === $item->seller_id));
            $fulfilOptions = collect($item->status->sellerTransitions())
                ->reject(fn ($s) => $s === OrderStatus::Cancelled);
            $linkable = $item->product && ! $item->product->trashed() && $item->product->is_active && $item->product->status === 'approved';
        @endphp
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;{{ !$loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}flex-wrap:wrap;{{ $cancelled ? 'opacity:0.6;' : '' }}">
            <div style="flex:1;min-width:200px;">
                <div style="font-size:0.82rem;font-weight:700;color:#f4f4f5;{{ $cancelled ? 'text-decoration:line-through;' : '' }}">
                    @if($linkable)
                        <a href="{{ route('products.show', $item->product) }}" style="color:inherit;text-decoration:none;">{{ $item->displayName() }}</a>
                    @else
                        {{ $item->displayName() }}
                    @endif
                </div>
                <div style="font-size:0.7rem;color:#71717a;">
                    @unless($isSeller){{ $item->displayStoreName() }} · @endunless
                    Qty {{ $item->quantity }} × ${{ number_format($item->unit_price, 2) }}
                    @if(! $linkable) · listing no longer sold @endif
                </div>
            </div>
            <x-status-pill :status="$item->status" size="sm" />
            <div style="text-align:right;min-width:90px;">
                <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;{{ $cancelled ? 'text-decoration:line-through;' : '' }}">${{ number_format($item->total, 2) }}</div>
                @if(! $cancelled && (float) $item->discount_amount > 0)
                <div style="font-size:0.65rem;color:#4ade80;">promo -${{ number_format($item->discount_amount, 2) }}</div>
                @endif
            </div>

            @if(($isSeller && $fulfilOptions->isNotEmpty()) || $canCancel)
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;flex-basis:100%;justify-content:flex-end;">
            @endif

            @if($isSeller && $fulfilOptions->isNotEmpty())
            <form method="POST" action="{{ route('seller.order-items.update-status', $item) }}" style="display:flex;gap:0.4rem;">
                @csrf
                @method('PATCH')
                <select name="status" class="dot-input" style="font-size:0.72rem;padding:0.35rem 0.5rem;" aria-label="Next status for {{ $item->displayName() }}">
                    @foreach($fulfilOptions as $option)
                    <option value="{{ $option->value }}">Mark {{ $option->label() }}</option>
                    @endforeach
                </select>
                <button type="submit" class="dot-btn dot-btn-primary" style="font-size:0.72rem;padding:0.35rem 0.7rem;">Update</button>
            </form>
            @endif

            @if($canCancel)
            <form method="POST" action="{{ route('order-items.cancel', $item) }}" onsubmit="return confirm('Cancel this item? It goes back into stock and the order total is recalculated.');">
                @csrf
                <button type="submit" class="dot-btn dot-btn-ghost" style="font-size:0.72rem;padding:0.35rem 0.7rem;color:#f87171;">Cancel item</button>
            </form>
            @endif

            @if(($isSeller && $fulfilOptions->isNotEmpty()) || $canCancel)
            </div>
            @endif
        </div>
        @endforeach
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
        @if($view['fullOrder'])
            <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:#a1a1aa;margin-bottom:0.4rem;">
                <span>Subtotal</span>
                <span>${{ number_format($order->subtotal, 2) }}</span>
            </div>
            @if((float) $order->discount_amount > 0)
            <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:#4ade80;margin-bottom:0.4rem;">
                <span>Promo ({{ $order->promo_code }})</span>
                <span>-${{ number_format($order->discount_amount, 2) }}</span>
            </div>
            @endif
            <div style="display:flex;justify-content:space-between;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#f4f4f5;padding-top:0.6rem;border-top:1px solid rgba(255,255,255,0.06);">
                <span>Total (unpaid)</span>
                <span>${{ number_format($order->total, 2) }}</span>
            </div>
            @if($lines->contains(fn ($l) => $l->isCancelled()))
            <div style="margin-top:0.6rem;font-size:0.7rem;color:#71717a;">Cancelled items are not counted in these totals.</div>
            @endif
        @else
            <div style="display:flex;justify-content:space-between;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#f4f4f5;">
                <span>Your items, net of promo</span>
                <span>${{ number_format($view['sellerNetCents'] / 100, 2) }}</span>
            </div>
            <div style="margin-top:0.6rem;font-size:0.7rem;color:#71717a;">Cancelled items are not counted. This is only your share of the order.</div>
        @endif
    </div>

    @if($view['shipping'])
    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <div style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.5rem;">
            {{ $isSeller ? 'Ship to' : 'Shipping details' }}
        </div>
        <div style="font-size:0.85rem;font-weight:600;color:#f4f4f5;">{{ $view['shipping']['name'] }}</div>
        @if($view['shipping']['address'])
        <div style="font-size:0.82rem;color:#d4d4d8;margin-top:0.15rem;">{{ $view['shipping']['address'] }}</div>
        @endif
        @if($view['shipping']['phone'])
        <div style="font-size:0.82rem;color:#d4d4d8;margin-top:0.15rem;">{{ $view['shipping']['phone'] }}</div>
        @endif
        @if($isSeller)
        <div style="font-size:0.68rem;color:#71717a;margin-top:0.6rem;">Shown only while you have items left to ship.</div>
        @endif
    </div>
    @elseif($isSeller)
    <div class="dot-card" style="padding:1.1rem 1.5rem;font-size:0.78rem;color:#71717a;">
        The buyer's shipping details are hidden because none of your items on this order is waiting to ship.
    </div>
    @endif

</div>
</x-app-layout>
