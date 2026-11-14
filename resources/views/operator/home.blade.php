{{--
  The operator's first page (R-51): what is waiting for a decision, longest wait first, what is not known yet, what is clear, and what was last decided. Fed by App\Services\Dashboard\OperatorHome
  (NeedsAPerson and OperatorAudit, unchanged). No motion on the queue rows beyond the one arrival: an operator reads this under pressure.
--}}
<x-operator-layout>
    <x-slot:title>Needs a person</x-slot:title>

    <div class="dx" data-dashboard="operator">
        <x-dx.arrive :headline="$headline" :sentence="$sentence" :primary="$primary" art="operator" />

        <div class="dx-desk">
            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-wait">
                    <div class="dx-hd"><h2 id="dx-wait">Waiting, longest wait first</h2></div>
                    @if ($waiting === [])
                        <x-dx.none top>Nothing is waiting for you.</x-dx.none>
                    @else
                        <ul class="dx-rows" aria-label="Queues with something waiting">
                            @foreach ($waiting as $item)
                                <x-dx.row :n="$item['count']" :title="$item['label']" :note="$item['note']" :href="route($item['route'], $item['params'] ?? [])" :class="$item['key'] === 'products' && $thumbs !== [] ? 'dx-row--faces' : null">
                                    <x-slot:aside>
                                        @if ($item['key'] === 'products' && $thumbs !== [])
                                            <span class="dx-faces" aria-hidden="true">@foreach ($thumbs as $t)<img class="dx-thumb dx-thumb--sm" src="{{ $t['url'] }}" alt="" width="32" height="32" loading="lazy" decoding="async">@endforeach</span>
                                        @endif
                                        @if ($item['oldest'])<span class="dx-row__age">Oldest <strong><x-op.age :at="$item['oldest']" /></strong></span>@endif
                                    </x-slot:aside>
                                </x-dx.row>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($unknown !== [])
                    <section class="dx-col" aria-labelledby="dx-unknown">
                        <div class="dx-hd"><h2 id="dx-unknown">Not known yet</h2></div>
                        <ul class="dx-rows">
                            @foreach ($unknown as $item)<x-dx.row :title="$item['label']" :note="$item['note']" :href="route($item['route'])" go="Open it" tone="hatch" />@endforeach
                        </ul>
                    </section>
                @endif
            </div>

            <div class="dx-col">
                <section class="dx-col" aria-labelledby="dx-recent">
                    <div class="dx-hd"><h2 id="dx-recent">Last decisions</h2><a href="{{ route('operator.audit') }}">Audit trail</a></div>
                    @if ($recent->isEmpty())
                        <x-dx.none top>No decision has been recorded yet. Each one you make appears here and in the audit trail.</x-dx.none>
                    @else
                        <ul class="dx-rows">
                            @foreach ($recent as $entry)
                                <li class="dx-row dx-row--nonum dx-row--static">
                                    <span class="dx-row__t"><strong>{{ $entry->actor_name }} {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($entry->action)) }}@if ($entry->subject_label): {{ $entry->subject_label }}@endif</strong><small><x-op.age :at="$entry->created_at" /> ago</small></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                @if ($clear !== [])
                    <section class="dx-col" aria-labelledby="dx-clear">
                        <div class="dx-hd"><h2 id="dx-clear">Nothing waiting</h2></div>
                        <ul class="dx-rows">
                            @foreach ($clear as $item)
                                <x-dx.row :title="$item['label']" :href="route($item['route'], $item['params'] ?? [])" compact>
                                    <x-slot:aside><span class="dx-st dx-st--pos"><x-ds.icon name="check-circle" :size="14" />Clear</span></x-slot:aside>
                                </x-dx.row>
                            @endforeach
                        </ul>
                    </section>
                @endif
            </div>
        </div>

        <x-dx.foot :items="$foot" />
    </div>
</x-operator-layout>
