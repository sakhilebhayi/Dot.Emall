{{--
  The payment, told as it truly stands. Variables: $order, $payment (OrderPage), $payable, $holdUntil, $mode, $r (route builder).
  A state comes from the order and its attempts as the SERVER recorded them; the flash "payment_outcome" only chooses the wording after a return from Paystack.
--}}
@php
    use App\Enums\OrderStatus;
    use App\Enums\PaymentStatus;
    use App\Support\Shop;
    $outcome = session('payment_outcome');
    $settled = $order->requires_payment && $order->payment_status->isSettled();
    $cancelled = $order->status === OrderStatus::Cancelled;
    $money = fn (int $c) => $order->money($c);
    // "Will pack your order" is only true while some line still waits to be packed; after that it is stale copy (R-43 buyer QA).
    $stillToPack = $order->items->contains(fn ($line) => in_array($line->status, [OrderStatus::Pending, OrderStatus::Processing], true));
@endphp
@if (! $order->requires_payment)
    <x-ds.alert tone="info" title="No payment was taken">This order was placed while card payment was not switched on, so it is pending and unpaid. The seller can see it.</x-ds.alert>
@elseif ($settled)
    <x-ds.alert tone="pos" title="{{ $outcome === 'paid' ? 'Payment received. Your order is confirmed.' : 'Payment received' }}">
        {{ $order->paid_at ? 'Paid '.$order->paid_at->format('j M Y, H:i').'.' : '' }}
        @if ($payment['channel']) Paid by {{ str_replace('_', ' ', $payment['channel']) }}.@endif
        @if ($stillToPack)The seller has been told and will pack your order.@endif @if ($mode === 'guest' && $stillToPack)A confirmation is on its way to {{ $order->guest_email }}.@endif
    </x-ds.alert>
@elseif ($cancelled)
    <x-ds.alert tone="info" title="This order was cancelled. Nothing is due.">It was not paid in time, or it was cancelled before payment. The items went back on sale and nothing was charged. @if ($mode === 'account')<a href="{{ route('cart.index') }}">Go to your cart</a> to order again.@endif</x-ds.alert>
@elseif ($payment['needs_review'] ?? false)
    <x-ds.alert tone="cau" title="We received a payment we could not match yet">It has been flagged for the Emall team to check. Please do not pay again. If you do not hear from us within a working day, use Report a problem below.</x-ds.alert>
@else
    @php
        $failed = $order->payment_status === PaymentStatus::Failed || in_array($outcome, ['failed', 'not_completed'], true);
        $title = match (true) {
            $outcome === 'unavailable' => 'We could not confirm your payment just now',
            in_array($outcome, ['failed', 'not_completed'], true) => 'Your payment was not completed',
            $order->payment_status === PaymentStatus::Failed => 'Your last payment attempt failed',
            $payment['open'] => 'Waiting for payment',
            default => 'Awaiting payment',
        };
    @endphp
    <x-ds.alert :tone="$failed ? 'crit' : 'cau'" :title="$title" class="co-payment">
        @if (app(\App\Services\Paystack\PaystackConfig::class)->isTestMode())<p><x-ds.badge tone="cau">TEST MODE</x-ds.badge> No real money moves: use a Paystack test card.</p>@endif
        <p>
            @if ($outcome === 'unavailable')
                We could not reach Paystack to check. If you paid, press Check payment again in a minute: we ask Paystack directly and update this page only when it confirms. Please do not pay twice.
            @elseif ($failed)
                Nothing was taken for this attempt. You can try again, and you can choose a different payment method on Paystack's page.
            @else
                Your items are held for you. Pay {{ $money($order->totalCents()) }} on Paystack to confirm the order.
            @endif
            @if ($holdUntil) Pay by <strong>{{ $holdUntil->format('j M, H:i') }}</strong>, or the order is cancelled and the items go back on sale.@endif
        </p>
        <div class="co-payment__actions">
            <form method="POST" action="{{ $r('pay') }}">@csrf<x-ds.button type="submit" icon="lock">{{ $failed ? 'Try payment again' : 'Pay now' }}</x-ds.button></form>
            <form method="POST" action="{{ $r('payment.check') }}">@csrf<x-ds.button type="submit" variant="quiet" icon="loader">Check payment again</x-ds.button></form>
        </div>
        <p class="ds-muted">Paid already? Press Check payment again: nothing here is marked paid until Paystack tells our server so. {{ $payment['attempts'] ?? 0 ? 'Payment attempts so far: '.$payment['attempts'].'.' : '' }}</p>
    </x-ds.alert>
@endif
