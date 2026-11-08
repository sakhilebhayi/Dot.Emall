@php
    use App\Enums\OrderStatus;
    use App\Services\Orders\OrderPage;
    use App\Support\Shop;

    $guest = $mode === 'guest';
    // One place builds each action address, for an account's order and a guest's alike.
    $r = fn (string $name, ...$args) => route(($guest ? 'guest.' : '').'orders.'.$name, array_merge([$order], $args));
    $money = fn (int $c) => $order->money($c);
    $lineCount = $access['lines']->count();
    $canReport = true;
@endphp
<x-dynamic-component :component="$guest ? 'store-layout' : 'account-layout'" :robots="'noindex'">
    <x-slot:title>Order {{ $order->reference() }}</x-slot:title>
    <x-slot:heading>Order {{ $order->reference() }}</x-slot:heading>
    <x-slot:lead>Placed {{ $order->created_at->format('j M Y, H:i') }}. {{ $lineCount }} {{ $lineCount === 1 ? 'item' : 'items' }} from {{ count($parcels) }} {{ count($parcels) === 1 ? 'seller' : 'sellers' }}.</x-slot:lead>
    <x-slot:actions>
        <x-ds.status kind="order" :state="$status['key']" />
        <x-ds.button :href="$r('receipt')" variant="quiet" icon="receipt">Receipt</x-ds.button>
        <form method="POST" action="{{ $r('reorder') }}">@csrf<x-ds.button type="submit" variant="quiet" icon="cart">Buy again</x-ds.button></form>
    </x-slot:actions>

    @if ($guest)
        <x-ds.alert tone="info" title="You are viewing this order without an account" class="co-gap">
            You can come back to this order any time from the link in your order e-mail. Keep that e-mail private: anyone with it can open this order.
            <a href="{{ route('login') }}">Sign in with InfoDot</a> using the same e-mail address ({{ $order->guest_email }}) to keep all your orders in one place. Accounts come from InfoDot.
        </x-ds.alert>
    @endif

    @include('orders._payment-panel', ['order' => $order, 'payment' => $payment, 'payable' => $payable, 'holdUntil' => $holdUntil, 'mode' => $mode, 'r' => $r])

    @if ($errors->any() && ! $errors->hasBag('message') && ! $errors->hasBag('report'))
        <x-ds.alert tone="crit" class="co-gap">{{ $errors->first() }}</x-ds.alert>
    @endif

    <div class="co-layout co-layout--order">
        <div class="co-main">
            <x-ds.order-timeline :steps="$steps" :code="$order->reference()" :across="count($steps) <= 6" />

            @foreach ($parcels as $parcel)
                @php
                    $pkey = OrderPage::keyFor($parcel['status'], $order);
                    $pid = $parcel['group']?->id ?? 'x';
                @endphp
                <section class="co-parcel" aria-labelledby="parcel-{{ $pid }}">
                    <header class="co-parcel__head">
                        <h2 id="parcel-{{ $pid }}">Parcel {{ $loop->iteration }}: {{ $parcel['store_name'] }}</h2>
                        <x-ds.status kind="order" :state="$parcel['status'] === OrderStatus::Cancelled ? 'cancelled' : $pkey" />
                    </header>

                    @if ($parcel['method'])
                        <p class="co-parcel__method"><x-ds.icon name="{{ $parcel['collection'] ? 'pin' : 'truck' }}" :size="18" /> {{ $parcel['method'] }}, {{ $parcel['delivery_cents'] === 0 ? 'free' : $money($parcel['delivery_cents']) }}.
                            @if ($parcel['estimate'])<span><strong>Estimated {{ $parcel['collection'] ? 'ready' : 'arrival' }}: {{ $parcel['estimate']['text'] }}.</strong> <span class="ds-muted">{{ $parcel['estimate']['source'] }}</span></span>
                            @elseif ($parcel['group'] && $parcel['group']->method_type === 'arranged')<span class="ds-muted">Delivery is arranged with the seller. There is no date.</span>@endif</p>
                    @endif

                    @foreach ($parcel['lines'] as $l)
                        @php $item = $l['item']; @endphp
                        <div class="co-orderline @if ($l['cancelled']) is-cancelled @endif">
                            <div class="co-orderline__main">
                                <p class="co-orderline__name">@if ($l['linkable'])<a href="{{ route('products.show', $item->product) }}">{{ $l['name'] }}</a>@else{{ $l['name'] }}@endif</p>
                                <p class="ds-muted">{{ $item->quantity }} &times; {{ $order->amount($item->unit_price) }}@if (! $l['linkable']), no longer on sale @endif</p>
                                @if (! $l['cancelled'] && (float) $item->discount_amount > 0)<p class="ds-muted">Promo share: {{ $order->amount(-1 * (float) $item->discount_amount) }}</p>@endif
                            </div>
                            <div class="co-orderline__side">
                                <x-ds.status kind="order" :state="$l['cancelled'] ? 'cancelled' : OrderPage::keyFor($item->status, $order)" />
                                <span class="co-orderline__total">{{ $order->amount($item->total) }}</span>
                            </div>

                            <div class="co-orderline__actions">
                                @if ($l['can_confirm'])
                                    <form method="POST" action="{{ $r('items.receive', $item) }}">@csrf<x-ds.button type="submit" size="sm" icon="package-check">{{ $l['confirm_label'] }}</x-ds.button></form>
                                @endif
                                @if ($l['can_return'])
                                    <x-ds.button :href="$r('returns.create', $item)" size="sm" variant="quiet" icon="return">Return this item</x-ds.button>
                                    <span class="ds-muted">Until {{ $l['return_until']->format('j M') }} ({{ $returnWindowDays }} days from delivery).</span>
                                @elseif ($l['return_note'] && $item->status->isCompleted())
                                    <span class="ds-muted">{{ $l['return_note'] }}</span>
                                @endif
                                @if ($l['can_cancel'])
                                    <x-ds.button size="sm" :variant="$l['can_confirm'] ? 'quiet' : 'danger'" icon="x-circle" data-dialog-open="cancel-{{ $item->id }}" :class="$l['can_confirm'] ? 'co-orderline__cancel' : ''">Cancel item</x-ds.button>
                                    <x-ds.confirm-dialog :id="'cancel-'.$item->id" title="Cancel this item?" :action="$guest ? $r('items.cancel', $item) : route('order-items.cancel', $item)" confirmLabel="Cancel item">
                                        <p>{{ $l['name'] }} goes back into stock and the order total is recalculated.@if ($order->payment_status->isSettled()) You paid for it, so the refund is recorded and sent through Paystack.@endif This cannot be undone.</p>
                                    </x-ds.confirm-dialog>
                                @endif
                            </div>

                            @if ($l['return'])
                                @php $ret = $l['return']; @endphp
                                <p class="co-orderline__return"><x-ds.icon name="return" :size="16" /> Return {{ strtolower($ret->status->label()) }}@if ($ret->status === \App\Enums\ReturnStatus::Declined && $ret->seller_response): the seller said: {{ $ret->seller_response }}@endif.
                                    @unless ($guest)<a href="{{ route('account.returns.show', $ret) }}">See the return</a>@endunless</p>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($parcel['shipments'] as $s)
                        <div class="co-shipment">
                            <h3><x-ds.icon name="truck" :size="18" /> Sent {{ $s['shipment']->shipped_at?->format('j M Y, H:i') }}</h3>
                            <p class="ds-muted co-note">Details from the seller</p>
                            <dl class="ds-dl">
                                @if ($s['carrier'])<dt>Carrier</dt><dd>{{ $s['carrier'] }}</dd>@endif
                                @if ($s['tracking_number'])
                                    <dt>Tracking number</dt>
                                    <dd><span class="ds-code">{{ $s['tracking_number'] }}</span>@if ($s['tracking_url']) <a href="{{ $s['tracking_url'] }}" rel="noopener noreferrer nofollow" target="_blank">Track with {{ $s['carrier'] }}<x-ds.icon name="external" :size="14" /></a>@else <span class="ds-muted">The seller did not give a carrier we can link to.</span>@endif</dd>
                                @else
                                    <dt>Tracking number</dt><dd class="ds-muted">The seller did not enter one.</dd>
                                @endif
                                @if ($s['shipment']->note)<dt>Seller's note</dt><dd>{{ $s['shipment']->note }}</dd>@endif
                            </dl>
                            @if ($s['events']->isNotEmpty())
                                <ol class="co-events" aria-label="Progress notes from the seller">
                                    @foreach ($s['events'] as $ev)
                                        <li><span class="co-events__when">{{ $ev->occurred_at?->format('j M, H:i') }}</span> {{ $ev->description }}@if ($ev->location) <span class="ds-muted">({{ $ev->location }})</span>@endif</li>
                                    @endforeach
                                </ol>
                                <p class="ds-muted">These notes were typed by the seller. Dot.Emall does not check them with the carrier.</p>
                            @endif
                        </div>
                    @endforeach

                    @if ($parcel['group'])
                        @php $gid = $parcel['group']->id; $openMsg = session('open_message') === $gid || $errors->hasBag('message') && old('group') == $gid; @endphp
                        <details class="co-contact" {{ $openMsg ? 'open' : '' }}>
                            <summary>Contact {{ $parcel['store_name'] }}</summary>
                            <form method="POST" action="{{ $r('message') }}">
                                @csrf
                                <input type="hidden" name="group" value="{{ $gid }}">
                                <x-ds.textarea label="Your message" name="body" id="msg-{{ $gid }}" rows="3" :value="old('group') == $gid ? old('body') : ''" :error="old('group') == $gid ? $errors->message->first('body') : null" hint="The seller sees your name and e-mail address so they can reply to you by e-mail, and this order number." />
                                <x-ds.button type="submit" variant="quiet" icon="message">Send to {{ $parcel['store_name'] }}</x-ds.button>
                            </form>
                        </details>
                    @endif
                </section>
            @endforeach
        </div>

        <aside class="co-side" aria-label="Order details">
            <x-ds.panel title="{{ $order->isAwaitingPayment() && $order->status !== OrderStatus::Cancelled ? 'What you will pay' : 'What you paid' }}">
                <dl class="co-money">
                    <div><dt>Items</dt><dd>{{ $money($totals['subtotal']) }}</dd></div>
                    @if ($totals['discount'] > 0)<div><dt>Discount @if ($order->promo_code)<span class="ds-code">{{ $order->promo_code }}</span>@endif</dt><dd>{{ $money(-$totals['discount']) }}</dd></div>@endif
                    @foreach ($parcels as $parcel)
                        @if ($parcel['group'])<div><dt>Delivery, {{ $parcel['store_name'] }}</dt><dd>{{ $parcel['delivery_cents'] === 0 ? 'Free' : $money($parcel['delivery_cents']) }}</dd></div>@endif
                    @endforeach
                    <div class="co-money__total"><dt>{{ $order->status === OrderStatus::Cancelled ? 'Total (cancelled)' : ($order->payment_status->isSettled() ? 'Total paid' : 'Total to pay') }}</dt><dd>{{ $money($totals['total']) }}</dd></div>
                    @if ($totals['vat'] > 0)<div><dt class="ds-muted">VAT included in the total</dt><dd class="ds-muted">{{ $money($totals['vat']) }}</dd></div>@endif
                </dl>
                @if ($access['lines']->contains(fn ($l) => $l->isCancelled()))<p class="ds-muted co-note">Cancelled items are not counted in these figures.</p>@endif
                @if ($totals['vat'] === 0)<p class="ds-muted co-note">Prices include VAT where the seller is VAT registered. This page shows a VAT amount only when a seller charges it.</p>@endif
            </x-ds.panel>

            @if ($access['shipping'])
                @php $snap = $access['shipping']['details'] ?? null; @endphp
                <x-ds.panel title="Delivery address">
                    @if (is_array($snap))
                        <x-ds.address-block :name="$snap['recipient_name'] ?? $access['shipping']['name']" :lines="[$snap['line1'] ?? null, $snap['line2'] ?? null, $snap['suburb'] ?? null, trim(($snap['city'] ?? '').' '.($snap['postal_code'] ?? '')), $snap['province'] ?? null]" :phone="$snap['phone'] ?? $access['shipping']['phone']" />
                        @if (! empty($snap['instructions']))<p class="ds-muted">Instructions: {{ $snap['instructions'] }}</p>@endif
                    @else
                        <x-ds.address-block :name="$access['shipping']['name']" :lines="[$access['shipping']['address']]" :phone="$access['shipping']['phone']" />
                    @endif
                    <p class="ds-muted co-note">This is the address as it was when you ordered. Changing your address book does not change it.</p>
                </x-ds.panel>
            @endif

            <x-ds.panel title="Payment">
                <dl class="ds-dl">
                    <dt>Status</dt>
                    <dd>{{ ! $order->requires_payment ? 'No payment taken' : ($order->payment_status->isSettled() ? ucfirst(str_replace('_', ' ', $order->payment_status->value)) : ($order->status === OrderStatus::Cancelled ? 'Cancelled, nothing charged' : 'Not paid yet')) }}</dd>
                    @if ($order->requires_payment)
                        <dt>Method</dt><dd>{{ $payment['channel'] ? ucfirst(str_replace('_', ' ', $payment['channel'])).' on Paystack' : ($payment['paid'] ? 'Paystack' : 'Chosen on Paystack when you pay') }}</dd>
                        @if ($payment['paid'])<dt>Paid</dt><dd>{{ $payment['paid']->paid_at?->format('j M Y, H:i') }}</dd>@endif
                        @if ($payment['reference'])<dt>Reference</dt><dd><span class="ds-code">{{ $payment['reference'] }}</span></dd>@endif
                    @endif
                </dl>
                @if ($refunds->isNotEmpty())
                    <h3 class="co-h3">Refunds</h3>
                    <ul class="co-list">
                        @foreach ($refunds as $refund)
                            <li>
                                @switch($refund->status)
                                    @case(\App\Enums\RefundStatus::Refunded)Refunded {{ Shop::money($refund->amount_cents, $refund->currency) }}{{ $refund->processed_at ? ' on '.$refund->processed_at->format('j M Y') : '' }}. It can take several business days to reach your account.@break
                                    @case(\App\Enums\RefundStatus::Submitted)Refund requested: {{ Shop::money($refund->amount_cents, $refund->currency) }}. Paystack is processing it; it is not complete until Paystack confirms.@break
                                    @case(\App\Enums\RefundStatus::Failed)Refund of {{ Shop::money($refund->amount_cents, $refund->currency) }} did not go through. The Emall team has it and will retry.@break
                                    @defaultRefund owed: {{ Shop::money($refund->amount_cents, $refund->currency) }}. It is recorded and being arranged; it has not been sent yet.
                                @endswitch
                                <span class="ds-muted">{{ $refund->kind === 'return' ? 'For a returned item.' : 'For cancelled items.' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ds.panel>

            <x-ds.panel title="Documents">
                <p><x-ds.button :href="$r('receipt')" variant="quiet" icon="receipt">Open the receipt</x-ds.button></p>
                <p class="ds-muted co-note">A receipt of this order. It is not a VAT tax invoice: none is issued yet.</p>
            </x-ds.panel>

            <x-ds.panel title="Something wrong?">
                <details class="co-contact" {{ session('open_report') ? 'open' : '' }}>
                    <summary>Report a problem to the Emall team</summary>
                    <form method="POST" action="{{ $r('report') }}">
                        @csrf
                        <x-ds.textarea label="What went wrong?" name="body" id="report-body" rows="3" :value="old('body')" :error="$errors->report->first('body')" hint="The Emall team can see this order. Use Contact on a parcel for questions to the seller." />
                        <x-ds.button type="submit" variant="quiet" icon="flag">Report a problem</x-ds.button>
                    </form>
                </details>
                <p class="ds-muted co-note"><a href="{{ route('help.returns') }}">How returns work</a> and <a href="{{ route('help.contact') }}">other ways to reach us</a>.</p>
            </x-ds.panel>
        </aside>
    </div>
</x-dynamic-component>
