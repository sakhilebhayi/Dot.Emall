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
    $paystack = app(\App\Services\Paystack\PaystackConfig::class);
    $awaitingPayment = $view['awaitingPayment'];
    $settled = $order->requires_payment && $order->payment_status->isSettled();
    $money = fn (int $cents) => $paystack->currency().' '.number_format($cents / 100, 2);
    $totalLabel = ! $order->requires_payment ? 'Total (unpaid)' : ($settled ? 'Total (paid)' : 'Total (due)');
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

    @if($isSeller)
        @if($awaitingPayment)
        <div style="margin-bottom:1.5rem;padding:0.7rem 1rem;border-radius:0.6rem;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.25);color:#f59e0b;font-size:0.8rem;" role="status">
            <strong>Awaiting payment.</strong> The buyer has not paid for this order yet. Do not ship it: you will be able to once it is paid.
        </div>
        @elseif($order->requires_payment)
        <div style="margin-bottom:1.5rem;font-size:0.75rem;color:#a1a1aa;">
            The buyer has paid. Payout is not available yet: your net amount is earned, not paid out.
        </div>
        @else
        <div style="margin-bottom:1.5rem;"></div>
        @endif
    @elseif(! $order->requires_payment)
    <div style="font-size:0.75rem;color:#a1a1aa;margin-bottom:1.5rem;">
        Payment is not collected on Dot.Emall yet. This order is <strong style="color:#f4f4f5;">pending and unpaid</strong>.
    </div>
    @else
    <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.5rem;" role="status">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
                <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
                    @if($settled)
                    <span style="font-size:0.7rem;font-weight:700;color:#22c55e;text-transform:uppercase;letter-spacing:0.08em;">Payment received</span>
                    @elseif($order->status === OrderStatus::Cancelled)
                    <span style="font-size:0.7rem;font-weight:700;color:#a1a1aa;text-transform:uppercase;letter-spacing:0.08em;">Cancelled, nothing due</span>
                    @elseif($order->payment_status === \App\Enums\PaymentStatus::Failed)
                    <span style="font-size:0.7rem;font-weight:700;color:#f87171;text-transform:uppercase;letter-spacing:0.08em;">Payment failed</span>
                    @else
                    <span style="font-size:0.7rem;font-weight:700;color:#f59e0b;text-transform:uppercase;letter-spacing:0.08em;">Awaiting payment</span>
                    @endif
                    @if($paystack->isTestMode())
                    <span style="padding:0.1rem 0.45rem;border-radius:4px;background:rgba(245,158,11,0.14);color:#f59e0b;font-weight:700;letter-spacing:0.06em;font-size:0.62rem;">TEST MODE</span>
                    @endif
                </div>
                @if($settled)
                <div style="font-size:0.78rem;color:#a1a1aa;margin-top:0.3rem;">{{ $order->paid_at ? 'Paid '.$order->paid_at->format('d M Y, H:i').'. ' : '' }}Your order is now with the seller.</div>
                @elseif($order->isPayable())
                <div class="metric-val" style="font-size:1.3rem;color:#f4f4f5;margin-top:0.3rem;">{{ $money($order->totalCents()) }}</div>
                <div style="font-size:0.72rem;color:#71717a;margin-top:0.3rem;">
                    Paid securely on Paystack; Dot.Emall never sees your card details.
                    Pay by {{ $order->created_at->copy()->addMinutes($paystack->unpaidWindowMinutes())->format('d M, H:i') }} or the order is cancelled and the items go back into stock.
                    @if($paystack->isTestMode())No real money moves: use a Paystack test card.@endif
                </div>
                @endif
            </div>
            @if($isBuyer && $order->isPayable())
            <form method="POST" action="{{ route('orders.pay', $order) }}">
                @csrf
                <button type="submit" class="dot-btn dot-btn-primary">
                    <span class="material-symbols-rounded" style="font-size:16px;">lock</span>
                    Pay now
                </button>
            </form>
            @endif
        </div>
        @if($view['refunds']->isNotEmpty())
        <div style="margin-top:0.9rem;padding-top:0.8rem;border-top:1px solid rgba(255,255,255,0.06);font-size:0.75rem;color:#a1a1aa;">
            @foreach($view['refunds'] as $refund)
            <div style="margin-bottom:0.25rem;">
                @switch($refund->status)
                    @case(\App\Enums\RefundStatus::Refunded)
                        Refunded {{ $money($refund->amount_cents) }} for cancelled items. It can take several business days to reach your account.
                        @break
                    @case(\App\Enums\RefundStatus::Submitted)
                        Refund requested: {{ $money($refund->amount_cents) }} for cancelled items. Paystack is processing it; it is not complete until Paystack confirms.
                        @break
                    @default
                        Refund owed: {{ $money($refund->amount_cents) }} for cancelled items. It is recorded and being arranged; it has not been sent yet.
                @endswitch
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">
        {{ session('status') }}
    </div>
    @endif
    @if (session('error'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;" role="alert">
        {{ session('error') }}
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
            // Nothing to pack or ship until the buyer has paid.
            $fulfilOptions = $awaitingPayment
                ? collect()
                : collect($item->status->sellerTransitions())->reject(fn ($s) => $s === OrderStatus::Cancelled);
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
                <span>{{ $totalLabel }}</span>
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
            <div style="margin-top:0.6rem;font-size:0.7rem;color:#71717a;">Cancelled items are not counted. This is only your share of the order.@if($order->requires_payment && ! $awaitingPayment) Earned, not paid out: payout is not available yet.@endif</div>
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
