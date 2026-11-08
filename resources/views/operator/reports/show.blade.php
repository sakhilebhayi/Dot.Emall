<x-operator-layout>
    <x-slot:title>{{ $item['label'] }}</x-slot:title>
    <x-slot:heading>{{ $item['label'] }}</x-slot:heading>
    <x-slot:lead>{{ $reports->count() }} {{ \Illuminate\Support\Str::plural('report', $reports->count()) }} from {{ $reports->pluck('reporter_id')->unique()->count() }} {{ \Illuminate\Support\Str::plural('person', $reports->pluck('reporter_id')->unique()->count()) }}. Read them all before you decide.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'Reports', 'href' => route('operator.reports')], ['label' => $item['label']]]" /></x-slot:breadcrumbs>
    <x-slot:actions>@if ($item['url'])<x-ds.button variant="quiet" :href="$item['url']" icon="external">Open the {{ $type }}</x-ds.button>@endif</x-slot:actions>

    <div class="op-split">
        <div class="ds-stack">
            @if ($item['excerpt'])<p class="op-quote">{{ $item['excerpt'] }}<small>What was reported</small></p>@endif

            <section class="op-block" aria-labelledby="who-h">
                <h2 id="who-h">The reports</h2>
                <ul class="op-items">
                    @foreach ($reports as $r)
                        <li class="op-item">
                            <div class="op-item__head"><strong>{{ str_replace('_', ' ', ucfirst($r->reason)) }}</strong><span class="op-muted"><x-op.age :at="$r->created_at" /> ago by {{ $r->reporter?->name ?? 'a former user' }}</span></div>
                            @if ($r->details)<p class="op-item__body">{{ $r->details }}</p>@endif
                            @if ($r->status !== 'open')<p class="op-quote">{{ $r->status === 'actioned' ? 'Taken down' : 'Dismissed' }} by {{ $r->handler?->name ?? 'an operator' }}: {{ $r->resolution }}</p>@endif
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($open > 0)
                <section class="op-block" aria-labelledby="decide-h">
                    <h2 id="decide-h">Your decision</h2>
                    @foreach ($errors->getBag('report-'.$type.'-'.$id)->get('report') as $message)<x-ds.alert tone="crit">{{ $message }}</x-ds.alert>@endforeach
                    <div class="op-decisions">
                        <x-op.decide :bag="'report-'.$type.'-'.$id" :action="route('operator.reports.resolve', ['type' => $type, 'id' => $id])" :summary="['product' => 'Take the listing down', 'store' => 'Suspend the store'][$type] ?? 'Take it down'" :submit="['product' => 'Take the listing down', 'store' => 'Suspend the store'][$type] ?? 'Take it down'" tone="danger" field="note" label="Outcome note" hint="Why it is going. The person who posted it, or the seller, reads it, and it is kept for the next time this comes up.">
                            <input type="hidden" name="action" value="remove">
                        </x-op.decide>
                        <x-op.decide :bag="'report-dismiss-'.$type.'-'.$id" :action="route('operator.reports.resolve', ['type' => $type, 'id' => $id])" summary="Dismiss the reports" submit="Dismiss, it stays up" tone="quiet" field="note" label="Outcome note" hint="Why it is fine. Kept with the item.">
                            <input type="hidden" name="action" value="dismiss">
                        </x-op.decide>
                    </div>
                </section>
            @endif
        </div>
        <aside class="op-side" aria-label="History">
            <section class="op-block">
                <h2>Decisions about this</h2>
                @forelse ($history as $entry)
                    <p class="op-muted"><strong>{{ $entry->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}, <x-op.age :at="$entry->created_at" /> ago.@if ($entry->reason)<br>{{ $entry->reason }}@endif</p>
                @empty<p class="op-muted">None yet.</p>@endforelse
            </section>
        </aside>
    </div>
</x-operator-layout>
