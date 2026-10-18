@php
    $hasStore = $stores->isNotEmpty();
    $money = fn (int $cents) => '$'.number_format($cents / 100, 2);

    if (! $hasStore) {
        $stateLine = 'You are not selling on Dot.Emall yet.';
    } elseif ($awaitingFulfilment > 0) {
        $stateLine = $awaitingFulfilment.' '.\Illuminate\Support\Str::plural('order', $awaitingFulfilment).' waiting on you to fulfil.';
    } else {
        $stateLine = 'Nothing is waiting on you.';
    }

    if ($hasStore && $awaitingPayment > 0) {
        $stateLine .= ' '.$awaitingPayment.' '.\Illuminate\Support\Str::plural('order', $awaitingPayment).' awaiting the buyer\'s payment: do not ship '.($awaitingPayment === 1 ? 'it' : 'them').' yet.';
    }

    if ($hasStore && $lowStockCount > 0) {
        $stateLine .= ' '.$lowStockCount.' '.\Illuminate\Support\Str::plural('listing', $lowStockCount).' low on stock.';
    }

    $cell = 'background:#141416;padding:1.1rem 1.4rem;';
    $label = 'font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;';
@endphp
<x-app-layout>

<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;flex-wrap:wrap;padding:1.75rem 2.5rem 0;">
    <div>
        <h1 style="margin:0;font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;">Seller desk</h1>
        <p style="margin:0.35rem 0 0;font-size:0.9rem;color:{{ $awaitingFulfilment > 0 ? '#f59e0b' : '#a1a1aa' }};font-weight:600;" role="status">{{ $stateLine }}</p>
    </div>
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
        <a href="{{ route('orders.index') }}" class="dot-btn dot-btn-ghost">
            <span class="material-symbols-rounded" style="font-size:16px;">receipt_long</span>
            My purchases{{ $myOrdersCount > 0 ? ' ('.$myOrdersCount.')' : '' }}
        </a>
        <a href="{{ route('products.index') }}" class="dot-btn dot-btn-primary">
            <span class="material-symbols-rounded" style="font-size:16px;">storefront</span>
            Browse marketplace
        </a>
    </div>
</div>

<div style="padding:1.75rem 2.5rem 3rem;">

    @if($hasStore)
    {{-- Ruled readout: panels share edges (1px gap over a ruled background). --}}
    <div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:1px;background:rgba(255,255,255,0.09);border:1px solid rgba(255,255,255,0.09);border-radius:4px;overflow:hidden;margin-bottom:2rem;">
        <div style="{{ $cell }}grid-column:span 2;">
            <div style="{{ $label }}">Delivered value</div>
            <div class="metric-val" style="font-size:2rem;color:#f59e0b;line-height:1.1;margin-top:0.4rem;">{{ $money($revenueCents) }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.35rem;">Delivered items, net of promos; orders still awaiting payment are left out. Payout is not available yet: amounts shown are earned, not paid out.</div>
        </div>
        <div style="{{ $cell }}">
            <div style="{{ $label }}">Orders</div>
            <div class="metric-val" style="font-size:1.4rem;color:#f4f4f5;margin-top:0.4rem;">{{ number_format($totalOrders) }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.35rem;">With your items</div>
        </div>
        <div style="{{ $cell }}">
            <div style="{{ $label }}">To fulfil</div>
            <div class="metric-val" style="font-size:1.4rem;color:{{ $awaitingFulfilment > 0 ? '#f59e0b' : '#f4f4f5' }};margin-top:0.4rem;">{{ number_format($awaitingFulfilment) }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.35rem;">Pending or processing</div>
        </div>
        <div style="{{ $cell }}">
            <div style="{{ $label }}">Listings</div>
            <div class="metric-val" style="font-size:1.4rem;color:#f4f4f5;margin-top:0.4rem;">{{ number_format($totalProducts) }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.35rem;">{{ $lowStockCount }} low on stock</div>
        </div>
        <div style="{{ $cell }}">
            <div style="{{ $label }}">Rating</div>
            <div class="metric-val" style="font-size:1.4rem;color:#f4f4f5;margin-top:0.4rem;">{{ $avgRating > 0 ? number_format($avgRating, 1) : '—' }}</div>
            <div style="font-size:0.68rem;color:#71717a;margin-top:0.35rem;">{{ number_format($totalReviews) }} {{ \Illuminate\Support\Str::plural('review', $totalReviews) }}</div>
        </div>
    </div>
    @endif

    {{-- ─── Store ─────────────────────────────────────────────────────────── --}}
    <div style="margin-bottom:2rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <h2 style="margin:0;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">My store</h2>
            @unless($hasStore)
            <a href="{{ route('seller.store.create') }}" style="font-size:0.75rem;font-weight:600;color:#f59e0b;text-decoration:none;">Open a store</a>
            @endunless
        </div>

        @if(! $hasStore)
        <div class="dot-card" style="padding:2.5rem 2rem;text-align:center;">
            <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.4rem;">No store yet</div>
            <div style="font-size:0.8rem;color:#71717a;margin-bottom:1.25rem;">Open a store to list products. Each person can run one store.</div>
            <a href="{{ route('seller.store.create') }}" class="dot-btn dot-btn-primary">Open my store</a>
        </div>
        @else
        @foreach($stores as $store)
        <div class="dot-card" style="padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;">
            <div style="min-width:0;flex:1;">
                <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                    <span style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;">{{ $store->name }}</span>
                    <span style="font-size:0.62rem;font-weight:700;padding:0.2rem 0.6rem;border-radius:9999px;text-transform:uppercase;letter-spacing:0.06em;{{ $store->is_active ? 'background:rgba(245,158,11,0.12);color:#f59e0b;' : 'background:rgba(107,114,128,0.15);color:#9ca3af;' }}">{{ $store->is_active ? 'Open' : 'Closed' }}</span>
                </div>
                @if($store->description)
                <p style="margin:0.35rem 0 0;font-size:0.75rem;color:#71717a;line-height:1.4;">{{ \Illuminate\Support\Str::limit($store->description, 160) }}</p>
                @endif
                <div style="font-size:0.7rem;color:#71717a;margin-top:0.4rem;">{{ $store->products_count }} {{ \Illuminate\Support\Str::plural('listing', $store->products_count) }}</div>
            </div>
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;">
                <a href="{{ route('seller.store.edit') }}" class="dot-btn dot-btn-ghost">Edit store</a>
                <a href="{{ route('seller.products.index') }}" class="dot-btn dot-btn-ghost">Manage listings</a>
            </div>
        </div>
        @endforeach
        @endif
    </div>

    {{-- ─── Recent orders: this seller's own slice of each order ─────────── --}}
    @if($hasStore)
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <h2 style="margin:0;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Recent orders</h2>
            <a href="{{ route('seller.orders.index') }}" style="font-size:0.75rem;font-weight:600;color:#f59e0b;text-decoration:none;">All orders to fulfil</a>
        </div>

        <div class="dot-card" style="overflow:hidden;">
            @if($recentOrders->isEmpty())
            <div style="padding:2.5rem;text-align:center;font-size:0.85rem;color:#71717a;">
                No orders for your store yet.
            </div>
            @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(67,70,86,0.3);">
                            @foreach(['Date' => 'left', 'Order' => 'left', 'Ship to' => 'left', 'Your items' => 'left', 'Your status' => 'left', 'Your net' => 'right'] as $heading => $align)
                            <th style="padding:0.85rem 1.25rem;text-align:{{ $align }};font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentOrders as $row)
                        <tr style="border-bottom:1px solid rgba(67,70,86,0.15);">
                            <td style="padding:0.9rem 1.25rem;font-size:0.75rem;color:#71717a;white-space:nowrap;">{{ $row['order']->created_at->format('d M Y') }}</td>
                            <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                                <a href="{{ route('orders.show', $row['order']) }}" style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#f4f4f5;text-decoration:none;">{{ $row['order']->reference() }}</a>
                            </td>
                            <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;white-space:nowrap;">{{ $row['buyerName'] ?? 'Hidden' }}</td>
                            <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;white-space:nowrap;">{{ $row['lineCount'] }}</td>
                            <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                                @if($row['awaitingPayment'])<x-status-pill status="awaiting payment" size="sm" />@else<x-status-pill :status="$row['status']" size="sm" />@endif
                            </td>
                            <td style="padding:0.9rem 1.25rem;text-align:right;white-space:nowrap;font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#f4f4f5;">{{ $money($row['netCents']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>
    @endif

</div>
</x-app-layout>
