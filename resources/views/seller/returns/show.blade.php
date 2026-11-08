@php use App\Enums\ReturnStatus as RS; $money = fn ($c) => $return->order->money($c); @endphp
<x-seller-layout>
    <x-slot:title>Return for {{ $item->displayName() }}</x-slot:title>
    <x-slot:heading>Return request</x-slot:heading>
    <x-slot:lead>{{ $item->displayName() }}, asked {{ $return->created_at->format('j M Y') }}.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Returns', 'href' => route('seller.returns')], ['label' => 'Return #'.$return->id]]" /></x-slot:breadcrumbs>

    <div class="sd-page">
        <x-seller.errors />

        <div class="sd-status-line">
            <x-ds.badge :tone="in_array($return->status->value, ['requested', 'escalated'], true) ? 'cau' : ($return->status->value === 'declined' ? 'crit' : 'info')">{{ $return->status->label() }}</x-ds.badge>
            @if ($daysLeft !== null)<span class="sd-countdown" @if ($daysLeft <= 2) style="color: var(--crit);" @endif><x-ds.icon name="clock" :size="18" />{{ $daysLeft < 0 ? 'Past the deadline: the Emall team may decide this for you.' : ($daysLeft === 0 ? 'Answer today, or it goes to the Emall team.' : $daysLeft.' '.($daysLeft === 1 ? 'day' : 'days').' left to answer, then it goes to the Emall team.') }}</span>@endif
        </div>

        <div class="sd-cols">
            <div class="sd-stack">
                <section aria-labelledby="r-why" class="sd-stack">
                    <h2 id="r-why" class="sd-h2">What the buyer says</h2>
                    <x-ds.dl>
                        <dt>Reason</dt><dd>{{ $return->reason_category->label() }}</dd>
                        <dt>Their words</dt><dd>{{ $return->note ?: 'They did not add a note.' }}</dd>
                        <dt>Who pays for the return</dt><dd>{{ $return->reason_category->sellerPaysReturn() ? 'You do: this reason means the item was not right.' : 'The buyer does: they changed their mind.' }}</dd>
                        <dt>Refund if it goes ahead</dt><dd><strong>{{ $money($return->refund_cents) }}</strong>, the item after any discount. Delivery is not refunded.</dd>
                        <dt>Delivered</dt><dd>{{ $return->delivered_at?->format('j M Y') ?? 'Not recorded' }}</dd>
                    </x-ds.dl>
                    @if ($photoCount > 0)
                        <div class="sd-photo-strip" aria-label="Photos from the buyer">
                            @for ($i = 0; $i < $photoCount; $i++)<a href="{{ route('seller.returns.photo', [$return, $i]) }}" target="_blank" rel="noopener"><img src="{{ route('seller.returns.photo', [$return, $i]) }}" alt="Photo {{ $i + 1 }} from the buyer" loading="lazy"></a>@endfor
                        </div>
                    @else
                        <p class="sd-quiet">The buyer attached no photos.</p>
                    @endif
                </section>

                @if ($return->status === RS::Requested)
                    <section aria-labelledby="r-answer" class="sd-stack">
                        <h2 id="r-answer" class="sd-h2">Your answer</h2>
                        <form method="POST" action="{{ route('seller.returns.approve', $return) }}" class="sd-do" novalidate>@csrf
                            <h3>Approve</h3>
                            <p class="sd-muted">The buyer is told to send the goods back. You refund them when the goods arrive.</p>
                            <x-ds.textarea label="Message to the buyer (optional)" name="note" id="approve-note" rows="2" maxlength="1000" :value="old('note')" hint="For example where to send it." />
                            <div><x-ds.button type="submit" variant="primary" icon="check-circle">Approve the return</x-ds.button></div>
                        </form>
                        <form method="POST" action="{{ route('seller.returns.decline', $return) }}" class="sd-do" novalidate>@csrf
                            <h3>Decline</h3>
                            <p class="sd-muted">You must say why. The buyer can ask the Emall team to look again, and they read your reason.</p>
                            <x-ds.textarea label="Why you are declining" name="reason" id="decline-reason" rows="3" maxlength="1000" :value="old('reason')" :required="true" :error="$errors->first('reason')" />
                            <div><x-ds.button type="submit" variant="danger" icon="ban">Decline the return</x-ds.button></div>
                        </form>
                    </section>
                @elseif ($return->status === RS::Approved)
                    <section aria-labelledby="r-recv" class="sd-stack">
                        <h2 id="r-recv" class="sd-h2">When the goods arrive</h2>
                        <form method="POST" action="{{ route('seller.returns.receive', $return) }}" class="sd-do" novalidate>@csrf
                            <p class="sd-muted">Check the item. Marking it received refunds the buyer {{ $money($return->refund_cents) }} straight away and takes it off your ledger. Only do it once you have the goods.</p>
                            <input type="hidden" name="restock" value="0">
                            <x-ds.toggle label="Put the item back in stock" name="restock" />
                            <p class="sd-quiet">Leave it off if the item is damaged or cannot be resold.</p>
                            <div><x-ds.button type="submit" variant="primary" icon="package-check">Mark received and refund</x-ds.button></div>
                        </form>
                    </section>
                @elseif ($return->status === RS::Declined)
                    <x-ds.alert tone="info" title="You declined this">{{ $return->seller_response }}</x-ds.alert>
                @elseif ($return->status === RS::Escalated)
                    <x-ds.alert tone="cau" title="With the Emall team">{{ $return->escalation_reason ?: 'The Emall team is deciding this return.' }} {{ $return->escalation_note }}</x-ds.alert>
                @elseif (in_array($return->status, [RS::Refunded, RS::Received], true))
                    <x-ds.alert tone="pos" title="Refunded">The buyer was refunded {{ $money($return->refund_cents) }}@if ($return->refunded_at) on {{ $return->refunded_at->format('j M Y') }}@endif.@if ($return->restock) The item went back into stock.@endif</x-ds.alert>
                @else
                    <x-ds.alert tone="info" title="Closed">{{ $return->resolution_note ?: 'This return is closed.' }}</x-ds.alert>
                @endif
            </div>
            <aside class="sd-stack sd-stack--tight">
                <h2 class="sd-h3">The order</h2>
                @if ($item->group)<a class="ds-btn ds-btn--quiet" href="{{ route('seller.orders.show', $item->group) }}">Open the parcel</a>@endif
                <p class="sd-quiet">Order {{ $return->order->reference() }}, item delivered or collected {{ $return->delivered_at?->format('j M') }}.</p>
            </aside>
        </div>
    </div>
</x-seller-layout>
