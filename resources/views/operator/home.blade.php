<x-operator-layout>
    <x-slot:title>Needs a person</x-slot:title>
    <x-slot:heading>Needs a person</x-slot:heading>
    <x-slot:lead>What is waiting for a decision, longest wait first. Each line goes to its queue.</x-slot:lead>

    <div class="op-split">
        <div class="ds-stack">
            @if ($waiting === [])
                <x-ds.empty-state title="Nothing is waiting for you" icon="check-circle">Every queue is empty. New listings, stores, reports and returns appear here the moment they need a decision.</x-ds.empty-state>
            @else
                <ol class="op-wait" aria-label="Queues with something waiting">
                    @foreach ($waiting as $item)
                        <li class="op-wait__row">
                            <span class="op-wait__n"><span class="ds-visually-hidden">{{ $item['label'] }}: </span>{{ number_format($item['count']) }}</span>
                            <div class="op-wait__body">
                                <a href="{{ route($item['route'], $item['params'] ?? []) }}">{{ $item['label'] }}<x-ds.icon name="arrow-right" :size="18" /></a>
                                <span class="op-wait__note">{{ $item['note'] }}</span>
                            </div>
                            <span class="op-wait__age">@if ($item['oldest'])Oldest <strong><x-op.age :at="$item['oldest']" /></strong> old @endif</span>
                        </li>
                    @endforeach
                </ol>
            @endif

            @if ($unknown !== [])
                <section class="op-block" aria-labelledby="unknown-h">
                    <h2 id="unknown-h">Not known yet</h2>
                    @foreach ($unknown as $item)
                        <x-ds.alert tone="cau" :title="$item['label']">{{ $item['note'] }} <a href="{{ route($item['route']) }}">Open it</a></x-ds.alert>
                    @endforeach
                </section>
            @endif

            @if ($clear !== [])
                <section class="op-block" aria-labelledby="clear-h">
                    <h2 id="clear-h">Nothing waiting</h2>
                    <ul class="op-clear">
                        @foreach ($clear as $item)
                            <li><x-ds.icon name="check-circle" :size="18" /><a href="{{ route($item['route'], $item['params'] ?? []) }}">{{ $item['label'] }}</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="op-side" aria-labelledby="recent-h">
            <section class="op-block">
                <h2 id="recent-h">Last decisions</h2>
                @forelse ($recent as $entry)
                    <p class="op-muted"><strong>{{ $entry->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}@if ($entry->subject_label): {{ $entry->subject_label }}@endif<br><x-op.age :at="$entry->created_at" /> ago</p>
                @empty
                    <p class="op-muted">No decision has been recorded yet. Each one you make appears here and in the audit trail.</p>
                @endforelse
                <a href="{{ route('operator.audit') }}">Open the audit trail</a>
            </section>
        </aside>
    </div>
</x-operator-layout>
