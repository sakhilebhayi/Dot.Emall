<x-operator-layout>
    <x-slot:title>Audit trail</x-slot:title>
    <x-slot:heading>Audit trail</x-slot:heading>
    <x-slot:lead>Every decision an operator makes about someone else, and every time personal data was opened in the support view. It can be read, never changed.</x-slot:lead>

    <x-op.chips label="Log" :items="[
        ['label' => 'Decisions', 'href' => route('operator.audit'), 'current' => $tab === 'decisions'],
        ['label' => 'Personal data opened', 'href' => route('operator.audit', ['tab' => 'access']), 'current' => $tab === 'access'],
    ]" />

    @if ($tab === 'decisions')
        <form method="GET" action="{{ route('operator.audit') }}" class="op-filters" aria-label="Filter decisions">
            <x-ds.select label="What" name="action" :options="array_merge(['listing.*' => 'Any listing decision', 'store.*' => 'Any store decision', 'review.*' => 'Any review decision', 'return.*' => 'Any return decision', 'refund.*' => 'Any refund decision', 'operator.*' => 'Operator access'], $actions)" :selected="$action" placeholder="Anything" />
            <x-ds.select label="Who" name="actor" :options="$actors->pluck('name', 'id')->all()" :selected="request('actor')" placeholder="Anyone" />
            <x-ds.button type="submit" variant="quiet">Filter</x-ds.button>
        </form>
        @if ($entries->isEmpty())
            <x-ds.empty-state title="Nothing recorded" icon="list">Decisions appear here the moment they are made.</x-ds.empty-state>
        @else
            <x-ds.table caption="Operator decisions, newest first">
                <x-slot:head><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th><th scope="col">About</th><th scope="col">Reason</th></tr></x-slot:head>
                @foreach ($entries as $e)
                    <tr>
                        <td data-label="When"><time datetime="{{ $e->created_at->toIso8601String() }}">{{ $e->created_at->format('d M Y H:i') }}</time></td>
                        <td data-label="Who">{{ $e->actor_name ?? 'Unknown' }}</td>
                        <td data-label="What">{{ app(\App\Services\Operator\OperatorAudit::class)->label($e->action) }}</td>
                        <td data-label="About">{{ $e->subject_label ?? 'None' }}@if ($e->subject_type)<br><span class="op-muted">{{ $e->subject_type }} #{{ $e->subject_id }}</span>@endif</td>
                        <td data-label="Reason">{{ $e->reason ?? 'None given' }}</td>
                    </tr>
                @endforeach
            </x-ds.table>
            <x-ds.pagination :paginator="$entries" />
        @endif
    @else
        <p class="op-lead">Each row is one time an operator opened a person's, a store's or an order's details in the support view. Searching shows only names and masked e-mail addresses, and is not logged.</p>
        @if ($entries->isEmpty())
            <x-ds.empty-state title="No personal data has been opened" icon="lock">Opening a person, store or order in the support view adds a row here.</x-ds.empty-state>
        @else
            <x-ds.table caption="Personal data opened, newest first">
                <x-slot:head><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Opened</th><th scope="col">Purpose</th></tr></x-slot:head>
                @foreach ($entries as $e)
                    <tr>
                        <td data-label="When"><time datetime="{{ $e->created_at->toIso8601String() }}">{{ $e->created_at->format('d M Y H:i') }}</time></td>
                        <td data-label="Who">{{ $e->actor_name ?? 'Unknown' }}</td>
                        <td data-label="Opened">{{ $e->subject_label }}<br><span class="op-muted">{{ $e->subject_type }} #{{ $e->subject_id }}</span></td>
                        <td data-label="Purpose">{{ $e->purpose ?? 'Not given' }}</td>
                    </tr>
                @endforeach
            </x-ds.table>
            <x-ds.pagination :paginator="$entries" />
        @endif
    @endif
</x-operator-layout>
