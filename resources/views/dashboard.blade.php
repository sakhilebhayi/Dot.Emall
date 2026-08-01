<x-app-layout>

{{-- ─── Page header ─────────────────────────────────────────────────────── --}}
<div style="display:flex;align-items:center;justify-content:space-between;padding:1.75rem 2.5rem 0;">
    <div>
        <h1 style="margin:0;font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;">
            Marketplace Dashboard
        </h1>
        <p style="margin:0.25rem 0 0;font-size:0.8rem;color:#71717a;">
            Overview of your stores, products and orders
        </p>
    </div>
    <div style="display:flex;gap:0.6rem;">
        <a href="{{ route('orders.index') }}" style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.25rem;border-radius:9999px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.08);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#f4f4f5;text-decoration:none;">
            <span class="material-symbols-rounded" style="font-size:16px;">receipt_long</span>
            My Orders
            @if($myOrdersCount > 0)
                <span style="background:rgba(245,158,11,0.18);color:#f59e0b;border-radius:9999px;padding:0.05rem 0.45rem;font-size:0.68rem;">{{ $myOrdersCount }}</span>
            @endif
        </a>
        <a href="{{ route('products.index') }}" style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#f59e0b,#d97706);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#1a1a1a;text-decoration:none;box-shadow:0 6px 18px rgba(245,158,11,0.3);">
            <span class="material-symbols-rounded" style="font-size:16px;">storefront</span>
            Browse Marketplace
        </a>
    </div>
</div>

<div style="padding:1.75rem 2.5rem 3rem;">

    {{-- ─── KPI row ──────────────────────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem;">

        {{-- Revenue --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Total Revenue</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(34,197,94,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#22c55e;">payments</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:#22c55e;line-height:1;">
                ${{ number_format($totalRevenue, 2) }}
            </div>
            <div style="margin-top:0.4rem;font-size:0.7rem;color:#71717a;">From delivered orders</div>
        </div>

        {{-- Total Orders --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Total Orders</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(96,165,250,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#60a5fa;">local_shipping</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:#60a5fa;line-height:1;">
                {{ number_format($totalOrders) }}
            </div>
            <div style="margin-top:0.4rem;font-size:0.7rem;color:#71717a;">Across all your stores</div>
        </div>

        {{-- Pending Orders --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Pending Orders</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(245,158,11,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#f59e0b;">pending</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:#f59e0b;line-height:1;">
                {{ number_format($pendingOrders) }}
            </div>
            <div style="margin-top:0.4rem;font-size:0.7rem;color:#71717a;">Awaiting confirmation</div>
        </div>

        {{-- Products --}}
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem 1.5rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                <span style="font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;">Products Listed</span>
                <div style="width:32px;height:32px;border-radius:8px;background:rgba(167,139,250,0.15);display:flex;align-items:center;justify-content:center;">
                    <span class="material-symbols-rounded" style="font-size:18px;color:#a78bfa;">inventory_2</span>
                </div>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1.75rem;font-weight:800;color:#a78bfa;line-height:1;">
                {{ number_format($totalProducts) }}
            </div>
            <div style="margin-top:0.4rem;font-size:0.7rem;color:#71717a;">Active in marketplace</div>
        </div>

    </div>

    {{-- ─── Secondary metrics row ───────────────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem;">
        <div class="dot-card" style="padding:1.1rem 1.4rem;display:flex;align-items:center;gap:0.9rem;">
            <div style="width:38px;height:38px;border-radius:9px;background:rgba(236,72,153,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span class="material-symbols-rounded" style="font-size:19px;color:#ec4899;">reviews</span>
            </div>
            <div>
                <div class="metric-val" style="font-size:1.15rem;color:#f4f4f5;">{{ number_format($totalReviews) }}</div>
                <div style="font-size:0.68rem;color:#71717a;">Reviews across your products</div>
            </div>
        </div>
        <div class="dot-card" style="padding:1.1rem 1.4rem;display:flex;align-items:center;gap:0.9rem;">
            <div style="width:38px;height:38px;border-radius:9px;background:rgba(250,204,21,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span class="material-symbols-rounded" style="font-size:19px;color:#facc15;">star</span>
            </div>
            <div>
                <div class="metric-val" style="font-size:1.15rem;color:#f4f4f5;">{{ $avgRating > 0 ? number_format($avgRating, 1) : '—' }}</div>
                <div style="font-size:0.68rem;color:#71717a;">Average product rating</div>
            </div>
        </div>
        <div class="dot-card" style="padding:1.1rem 1.4rem;display:flex;align-items:center;gap:0.9rem;{{ $lowStockCount > 0 ? 'border-color:rgba(239,68,68,0.3);' : '' }}">
            <div style="width:38px;height:38px;border-radius:9px;background:rgba(239,68,68,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span class="material-symbols-rounded" style="font-size:19px;color:#ef4444;">warning</span>
            </div>
            <div>
                <div class="metric-val" style="font-size:1.15rem;color:#f4f4f5;">{{ number_format($lowStockCount) }}</div>
                <div style="font-size:0.68rem;color:#71717a;">Products low on stock (&lt; 5)</div>
            </div>
        </div>
    </div>

    {{-- ─── Stores section ───────────────────────────────────────────────── --}}
    <div style="margin-bottom:2rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <h2 style="margin:0;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">My Stores</h2>
            <a href="#" style="font-size:0.75rem;font-weight:600;color:#f59e0b;text-decoration:none;">+ Create Store</a>
        </div>

        @if($stores->isEmpty())
        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:3rem 2rem;text-align:center;">
            <div style="width:56px;height:56px;border-radius:14px;background:rgba(245,158,11,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <span class="material-symbols-rounded" style="font-size:28px;color:#f59e0b;">storefront</span>
            </div>
            <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin-bottom:0.4rem;">No stores yet</div>
            <div style="font-size:0.8rem;color:#71717a;margin-bottom:1.25rem;">Create your first store to start selling on Dot.Emall.</div>
            <a href="#" style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.5rem;border-radius:9999px;background:linear-gradient(135deg,#f59e0b,#d97706);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#1a1a1a;text-decoration:none;">
                <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                Create First Store
            </a>
        </div>
        @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;">
            @foreach($stores as $store)
            <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:0.75rem;">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span class="material-symbols-rounded" style="font-size:20px;color:#1a1a1a;">store</span>
                    </div>
                    <div style="min-width:0;">
                        <div style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            {{ $store->name }}
                        </div>
                        <div style="font-size:0.65rem;color:#71717a;">{{ $store->slug }}</div>
                    </div>
                    <div style="margin-left:auto;flex-shrink:0;">
                        @if($store->is_active)
                        <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.2rem 0.6rem;border-radius:9999px;background:rgba(34,197,94,0.12);font-size:0.62rem;font-weight:700;color:#22c55e;text-transform:uppercase;letter-spacing:0.06em;">
                            <span style="width:5px;height:5px;border-radius:9999px;background:#22c55e;"></span>Active
                        </span>
                        @else
                        <span style="display:inline-flex;align-items:center;gap:0.25rem;padding:0.2rem 0.6rem;border-radius:9999px;background:rgba(107,114,128,0.15);font-size:0.62rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.06em;">
                            <span style="width:5px;height:5px;border-radius:9999px;background:#6b7280;"></span>Inactive
                        </span>
                        @endif
                    </div>
                </div>
                @if($store->description)
                <p style="margin:0;font-size:0.75rem;color:#71717a;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                    {{ $store->description }}
                </p>
                @endif
                <div style="display:flex;gap:1rem;padding-top:0.5rem;border-top:1px solid rgba(255,255,255,0.06);">
                    <div style="display:flex;align-items:center;gap:0.35rem;">
                        <span class="material-symbols-rounded" style="font-size:15px;color:#a78bfa;">inventory_2</span>
                        <span style="font-size:0.72rem;font-weight:600;color:#f4f4f5;">{{ $store->products_count }}</span>
                        <span style="font-size:0.68rem;color:#71717a;">products</span>
                    </div>
                    <a href="#" style="margin-left:auto;font-size:0.7rem;font-weight:600;color:#f59e0b;text-decoration:none;display:flex;align-items:center;gap:0.2rem;">
                        Manage
                        <span class="material-symbols-rounded" style="font-size:13px;">arrow_forward</span>
                    </a>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- ─── Recent Orders table ──────────────────────────────────────────── --}}
    <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
            <h2 style="margin:0;font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;">Recent Orders</h2>
            <a href="#" style="font-size:0.75rem;font-weight:600;color:#f59e0b;text-decoration:none;">View all</a>
        </div>

        <div style="background:#141416;border:1px solid rgba(255,255,255,0.06);border-radius:1rem;overflow:hidden;">
            @if($recentOrders->isEmpty())
            <div style="padding:2.5rem;text-align:center;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#71717a;display:block;margin-bottom:0.75rem;">receipt_long</span>
                <div style="font-size:0.85rem;color:#71717a;">No orders yet. Share your products to start selling!</div>
            </div>
            @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid rgba(67,70,86,0.3);">
                            <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Date</th>
                            <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Order #</th>
                            <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Customer</th>
                            <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Store</th>
                            <th style="padding:0.85rem 1.25rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Status</th>
                            <th style="padding:0.85rem 1.25rem;text-align:right;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.1em;white-space:nowrap;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentOrders as $order)
                        @php
                            $storeName = $order->items->first()?->product?->store?->name ?? '—';

                            $statusStyles = match($order->status) {
                                'pending'    => ['bg' => 'rgba(245,158,11,0.12)',  'color' => '#f59e0b'],
                                'confirmed',
                                'processing' => ['bg' => 'rgba(96,165,250,0.12)',  'color' => '#60a5fa'],
                                'shipped'    => ['bg' => 'rgba(34,211,238,0.12)',  'color' => '#22d3ee'],
                                'delivered'  => ['bg' => 'rgba(34,197,94,0.12)',   'color' => '#22c55e'],
                                'cancelled'  => ['bg' => 'rgba(239,68,68,0.12)',   'color' => '#ef4444'],
                                'refunded'   => ['bg' => 'rgba(167,139,250,0.12)', 'color' => '#a78bfa'],
                                default      => ['bg' => 'rgba(107,114,128,0.12)', 'color' => '#6b7280'],
                            };
                        @endphp
                        <tr style="border-bottom:1px solid rgba(67,70,86,0.15);transition:background 0.15s;" onmouseover="this.style.background='rgba(26,36,56,0.6)'" onmouseout="this.style.background='transparent'">
                            <td style="padding:0.9rem 1.25rem;font-size:0.75rem;color:#71717a;white-space:nowrap;">
                                {{ $order->created_at->format('d M Y') }}
                            </td>
                            <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                                <span style="font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#f4f4f5;">#{{ str_pad($order->id, 5, '0', STR_PAD_LEFT) }}</span>
                            </td>
                            <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;white-space:nowrap;">
                                {{ $order->user->name ?? '—' }}
                            </td>
                            <td style="padding:0.9rem 1.25rem;font-size:0.78rem;color:#a1a1aa;white-space:nowrap;">
                                {{ $storeName }}
                            </td>
                            <td style="padding:0.9rem 1.25rem;white-space:nowrap;">
                                <span style="display:inline-block;padding:0.2rem 0.65rem;border-radius:9999px;background:{{ $statusStyles['bg'] }};font-size:0.65rem;font-weight:700;color:{{ $statusStyles['color'] }};text-transform:capitalize;letter-spacing:0.04em;">
                                    {{ $order->status }}
                                </span>
                            </td>
                            <td style="padding:0.9rem 1.25rem;text-align:right;white-space:nowrap;">
                                <span style="font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#f4f4f5;">${{ number_format($order->total, 2) }}</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

</div>
</x-app-layout>
