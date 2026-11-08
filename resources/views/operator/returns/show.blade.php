<x-operator-layout>
    <x-slot:title>Return #{{ $return->id }}</x-slot:title>
    <x-slot:heading>Return #{{ $return->id }}</x-slot:heading>
    <x-slot:lead>Read both sides, then decide. The buyer sees your decision on their order; the seller is told too.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Returns', 'href' => route('operator.returns.index')], ['label' => 'Return #'.$return->id]]" /></x-slot:breadcrumbs>
    <x-slot:actions><x-ds.badge :tone="['escalated' => 'cau', 'approved' => 'pos', 'refunded' => 'pos', 'closed' => 'neutral', 'declined' => 'crit'][$return->status->value] ?? 'neutral'">{{ $return->status->label() }}</x-ds.badge></x-slot:actions>

    <div class="op-split">
        <div class="ds-stack">
            <section class="op-block" aria-labelledby="case-h">
                <h2 id="case-h">The case</h2>
                <x-ds.dl>
                    <dt>Item</dt><dd>{{ $return->item?->displayName() }} × {{ $return->item?->quantity }}</dd>
                    <dt>Order</dt><dd><a href="{{ route('operator.people.order', $return->order) }}">{{ $return->order->reference() }}</a> (opens the support view and is logged)</dd>
                    <dt>Reason given</dt><dd>{{ $return->reason_category->label() }}</dd>
                    <dt>Refund if approved</dt><dd class="op-num">{{ \App\Support\Shop::money((int) $return->refund_cents, $return->order->currency) }} (the item after its share of any promo; delivery is not refunded)</dd>
                    <dt>Asked</dt><dd><x-op.age :at="$return->created_at" /> ago, delivered {{ $return->delivered_at?->format('d M Y') ?? 'on an unknown date' }}</dd>
                    <dt>Came to you</dt><dd><x-op.age :at="$return->escalated_at" /> ago</dd>
                </x-ds.dl>
            </section>
            <section class="op-block" aria-labelledby="buyer-h">
                <h2 id="buyer-h">The buyer said</h2>
                <p class="op-quote">{{ $return->note ?: 'The buyer wrote nothing.' }}</p>
                @if ($return->escalation_note)<p class="op-quote">{{ $return->escalation_note }}<small>When they disputed the decline</small></p>@endif
            </section>
            <section class="op-block" aria-labelledby="seller-h">
                <h2 id="seller-h">The seller {{ $return->seller_response ? 'said' : 'did not answer' }}</h2>
                @if ($return->seller_response)<p class="op-quote">{{ $return->seller_response }}<small>{{ $return->seller?->name }}</small></p>@else<p class="op-muted">{{ $return->seller?->name }} had until {{ $return->respond_by?->format('d M Y') ?? 'an unknown date' }}.</p>@endif
            </section>

            @if ($return->status === \App\Enums\ReturnStatus::Escalated)
                <section class="op-block" aria-labelledby="decide-h">
                    <h2 id="decide-h">Your decision</h2>
                    <div class="op-decisions">
                        <x-op.decide :bag="'return-'.$return->id" :action="route('operator.returns.settle', $return)" summary="Decide this return" submit="Record the decision" tone="primary" field="note" label="Why, in words both sides can read">
                            <x-ds.select label="Decision" name="decision" :options="['approve' => 'Approve the return: the refund follows when the seller marks the goods received', 'approve_refund' => 'Approve and refund now: the goods are back, or the case does not need them', 'uphold_decline' => 'Uphold the seller\'s decision: close the return with no refund']" :selected="old('decision', 'approve')" id="return-decision" />
                        </x-op.decide>
                    </div>
                </section>
            @else
                <section class="op-block"><h2>Decision</h2>
                    @if ($return->resolution_note)<p class="op-quote">{{ $return->resolution_note }}<small>Settled <x-op.age :at="$return->responded_at" /> ago</small></p>@else<p class="op-muted">This return is {{ strtolower($return->status->label()) }}.</p>@endif
                    @if ($return->refund)<p class="op-muted">Refund #{{ $return->refund->id }}: {{ \App\Support\Shop::money((int) $return->refund->amount_cents, $return->refund->currency) }}, {{ str_replace('_', ' ', $return->refund->status->value) }}.</p>@endif
                </section>
            @endif
        </div>
        <aside class="op-side" aria-label="Context">
            <section class="op-block"><h2>History</h2>
                @forelse ($history as $entry)
                    <p class="op-muted"><strong>{{ $entry->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}, <x-op.age :at="$entry->created_at" /> ago.@if ($entry->reason)<br>{{ $entry->reason }}@endif</p>
                @empty<p class="op-muted">No operator decision yet.</p>@endforelse
            </section>
        </aside>
    </div>
</x-operator-layout>
