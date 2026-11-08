<x-seller-layout>
    <x-slot:title>Orders to fulfil</x-slot:title>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.75rem;">
        <h1 style="font-family:var(--font-text);font-size:1.6rem;font-weight:800;color:var(--ink);letter-spacing:-0.02em;;margin:0;">
            Orders to Fulfill
        </h1>
        <div style="font-size:0.78rem;color:var(--ink-3);margin-top:0.25rem;">
            Only your own items are shown. Other sellers' items and the order total stay private.
        </div>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($orders->isEmpty())
        <div style="padding:2.5rem;text-align:center;">
            <x-ds.icon name="truck" :size="36" />
            <div style="font-size:0.85rem;color:var(--ink-3);">No orders yet.</div>
        </div>
        @else
        <div class="table-scroll" style="overflow-x:auto;" role="region" aria-label="Orders to fulfil" tabindex="0">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--line);">
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Date</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Order #</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Ship to</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Your items</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Your status</th>
                        <th style="padding:0.85rem 1.25rem;text-align:right;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Your net</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    @php
                        $mine = $order->items;
                        $live = $mine->reject(fn ($l) => $l->isCancelled());
                        $sliceStatus = \App\Enums\OrderStatus::derive($mine->pluck('status'));
                        $toShip = $order->isFulfillable() && $mine->contains(fn ($l) => $l->status->needsShipping());
                        $awaitingPayment = $order->isAwaitingPayment() && $live->isNotEmpty();
                        $net = $live->sum(fn ($l) => $l->netCents());
                    @endphp
                    <tr style="border-bottom:1px solid var(--line);">
                        <td style="padding:0.9rem 1.25rem;font-size:0.75rem;color:var(--ink-3);white-space:nowrap;">{{ $order->created_at->format('d M Y') }}</td>
                        <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                            <a href="{{ route('orders.show', $order) }}" style="font-family:var(--font-text);font-size:0.78rem;font-weight:700;color:var(--ink);text-decoration:none;">
                                {{ $order->reference() }}
                            </a>
                        </td>
                        <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:var(--ink-2);">{{ $toShip ? ($order->shipping_name ?: ($order->user->name ?? 'None')) : 'Hidden' }}</td>
                        <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:var(--ink-2);">
                            {{ $live->count() }} item{{ $live->count() === 1 ? '' : 's' }}
                            @if($mine->count() > $live->count())
                                <span style="color:var(--ink-3);">({{ $mine->count() - $live->count() }} cancelled)</span>
                            @endif
                        </td>
                        <td style="padding:0.9rem 1.25rem;">
                            @if($awaitingPayment)<x-status-pill status="awaiting payment" size="sm" />@else<x-status-pill :status="$sliceStatus" size="sm" />@endif
                        </td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;font-family:var(--font-text);font-size:0.82rem;font-weight:700;color:var(--ink);">{{ $order->money($net) }}</td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;">
                            <a href="{{ route('orders.show', $order) }}" style="font-size:0.7rem;font-weight:600;color:var(--brand);text-decoration:none;">Manage →</a>
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
</x-seller-layout>
