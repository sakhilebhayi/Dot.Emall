<x-account-layout>
    <x-slot:title>Your orders</x-slot:title>

<div style="padding:2rem 2.5rem 3rem;">

    <div style="margin-bottom:1.75rem;">
        <h1 style="font-family:var(--font-text);font-size:1.6rem;font-weight:800;color:var(--ink);letter-spacing:-0.02em;;margin:0;">
            My Orders
        </h1>
        <div style="font-size:0.78rem;color:var(--ink-3);margin-top:0.25rem;">
            Orders you've placed as a buyer across the marketplace
        </div>
    </div>

    <div class="dot-card" style="overflow:hidden;">
        @if($orders->isEmpty())
        <div style="padding:2.5rem;text-align:center;">
            <x-ds.icon name="receipt" :size="36" />
            <div style="font-size:0.85rem;color:var(--ink-3);margin-bottom:1rem;">You haven't placed any orders yet.</div>
            <a href="{{ route('products.index') }}" style="font-size:0.78rem;font-weight:600;color:var(--brand);text-decoration:none;">Browse the marketplace →</a>
        </div>
        @else
        <div class="table-scroll" style="overflow-x:auto;" role="region" aria-label="Your orders" tabindex="0">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--line);">
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Date</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Order #</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Items</th>
                        <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Status</th>
                        <th style="padding:0.85rem 1.25rem;text-align:right;font-size:0.65rem;font-weight:700;color:var(--ink-3);text-transform:uppercase;letter-spacing:0.1em;">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orders as $order)
                    <tr style="border-bottom:1px solid var(--line);">
                        <td style="padding:0.9rem 1.25rem;font-size:0.75rem;color:var(--ink-3);white-space:nowrap;">{{ $order->created_at->format('d M Y') }}</td>
                        <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                            <a href="{{ route('orders.show', $order) }}" style="font-family:var(--font-text);font-size:0.78rem;font-weight:700;color:var(--ink);text-decoration:none;">
                                {{ $order->reference() }}
                            </a>
                        </td>
                        <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:var(--ink-2);">{{ $order->items->count() }} item{{ $order->items->count() === 1 ? '' : 's' }}</td>
                        <td style="padding:0.9rem 1.25rem;">
                            <x-status-pill :status="$order->status" size="sm" />
                            @if($order->requires_payment && $order->status !== \App\Enums\OrderStatus::Cancelled)
                                @if($order->payment_status->isSettled())
                                <x-status-pill status="paid" size="sm" />
                                @elseif($order->payment_status === \App\Enums\PaymentStatus::Failed)
                                <x-status-pill status="payment failed" size="sm" />
                                @else
                                <x-status-pill status="awaiting payment" size="sm" />
                                @endif
                            @endif
                        </td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;font-family:var(--font-text);font-size:0.82rem;font-weight:700;color:var(--ink);">{{ $order->amount($order->total) }}</td>
                        <td style="padding:0.9rem 1.25rem;text-align:right;">
                            <a href="{{ route('orders.show', $order) }}" style="font-size:0.7rem;font-weight:600;color:var(--brand);text-decoration:none;">View →</a>
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
</x-account-layout>
