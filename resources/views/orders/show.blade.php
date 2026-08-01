<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;max-width:800px;">

    <a href="{{ route('orders.index') }}" style="display:inline-flex;align-items:center;gap:0.3rem;font-size:0.75rem;color:#71717a;text-decoration:none;margin-bottom:1.25rem;">
        <span class="material-symbols-rounded" style="font-size:15px;">arrow_back</span>
        Back to my orders
    </a>

    @php
        $statusStyles = match($order->status) {
            'pending'    => ['bg' => 'rgba(245,158,11,0.12)',  'color' => '#f59e0b'],
            'confirmed', 'processing' => ['bg' => 'rgba(96,165,250,0.12)',  'color' => '#60a5fa'],
            'shipped'    => ['bg' => 'rgba(34,211,238,0.12)',  'color' => '#22d3ee'],
            'delivered'  => ['bg' => 'rgba(34,197,94,0.12)',   'color' => '#22c55e'],
            'cancelled'  => ['bg' => 'rgba(239,68,68,0.12)',   'color' => '#ef4444'],
            'refunded'   => ['bg' => 'rgba(167,139,250,0.12)', 'color' => '#a78bfa'],
            default      => ['bg' => 'rgba(107,114,128,0.12)', 'color' => '#6b7280'],
        };
    @endphp

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.75rem;">
        <div>
            <div style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:#f4f4f5;">
                Order #{{ str_pad($order->id, 5, '0', STR_PAD_LEFT) }}
            </div>
            <div style="font-size:0.78rem;color:#71717a;margin-top:0.2rem;">Placed {{ $order->created_at->format('d M Y, H:i') }}</div>
        </div>
        <span style="display:inline-block;padding:0.3rem 0.85rem;border-radius:9999px;background:{{ $statusStyles['bg'] }};font-size:0.72rem;font-weight:700;color:{{ $statusStyles['color'] }};text-transform:capitalize;">{{ $order->status }}</span>
    </div>

    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;">
        @foreach($order->items as $item)
        <div style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;{{ !$loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
            <div style="flex:1;min-width:0;">
                <div style="font-size:0.82rem;font-weight:700;color:#f4f4f5;">{{ $item->product->name ?? 'Product removed' }}</div>
                <div style="font-size:0.7rem;color:#71717a;">{{ $item->product->store->name ?? '—' }} · Qty {{ $item->quantity }} × ${{ number_format($item->unit_price, 2) }}</div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;">${{ number_format($item->total, 2) }}</div>
        </div>
        @endforeach
    </div>

    <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1.5rem;">
        <div style="display:flex;justify-content:space-between;font-size:0.8rem;color:#a1a1aa;margin-bottom:0.4rem;">
            <span>Subtotal</span>
            <span>${{ number_format($order->subtotal, 2) }}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-family:'Syne',sans-serif;font-size:1rem;font-weight:800;color:#f4f4f5;padding-top:0.6rem;border-top:1px solid rgba(255,255,255,0.06);">
            <span>Total</span>
            <span>${{ number_format($order->total, 2) }}</span>
        </div>
    </div>

    @if($order->shipping_address)
    <div class="dot-card" style="padding:1.25rem 1.5rem;">
        <div style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:0.4rem;">Shipping Address</div>
        <div style="font-size:0.82rem;color:#d4d4d8;">{{ $order->shipping_address }}</div>
    </div>
    @endif

</div>
</x-app-layout>
