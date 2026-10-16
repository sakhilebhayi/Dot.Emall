<x-app-layout>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.75rem;">
        <div style="font-family:'Syne',sans-serif;font-size:1.6rem;font-weight:800;color:#f4f4f5;letter-spacing:-0.02em;">
            Orders to Fulfill
        </div>
        <div style="font-size:0.78rem;color:#71717a;margin-top:0.25rem;">
            Orders containing items from your store(s)
        </div>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($orders->isEmpty())
        <div style="padding:2.5rem;text-align:center;">
            <span class="material-symbols-rounded" style="font-size:36px;color:#71717a;display:block;margin-bottom:0.75rem;">local_shipping</span>
            <div style="font-size:0.85rem;color:#71717a;">No orders yet.</div>
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(67,70,86,0.3);">
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Date</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Order #</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Buyer</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Your Items</th>
                        <th style="padding:0.85rem 1.25rem;text-align:right;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    <tr style="border-bottom:1px solid rgba(67,70,86,0.15);">
                        <td style="padding:0.9rem 1.25rem;font-size:0.75rem;color:#71717a;white-space:nowrap;">{{ $order->created_at->format('d M Y') }}</td>
                        <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                            <a href="{{ route('orders.show', $order) }}" style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#f4f4f5;text-decoration:none;">
                                #{{ str_pad($order->id, 5, '0', STR_PAD_LEFT) }}
                            </a>
                        </td>
                        <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;">{{ $order->user->name ?? '—' }}</td>
                        <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;">
                            {{ $order->items->count() }} item{{ $order->items->count() === 1 ? '' : 's' }}
                        </td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#f4f4f5;">${{ number_format($order->total, 2) }}</td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;">
                            <a href="{{ route('orders.show', $order) }}" style="font-size:0.7rem;font-weight:600;color:#f59e0b;text-decoration:none;">Manage →</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem 1.25rem;">{{ $orders->links() }}</div>
        @endif
    </div>

</div>
</x-app-layout>
