<x-operator-layout>
    <x-slot:title>Platform health</x-slot:title>
    <x-slot:heading>Platform health</x-slot:heading>
    <x-slot:lead>What is running and what is not, from records. "Unknown" means there is no record, which is not the same as fine.</x-slot:lead>

    @if ($attention > 0)<x-ds.alert tone="cau" title="{{ $attention }} {{ \Illuminate\Support\Str::plural('thing', $attention) }} need a look">Marked below.</x-ds.alert>
    @elseif ($unknown > 0)<x-ds.alert tone="info" title="Nothing is failing, but {{ $unknown }} {{ $unknown === 1 ? 'line is' : 'lines are' }} unknown">No record exists for {{ $unknown === 1 ? 'it' : 'them' }} yet.</x-ds.alert>
    @else<x-ds.alert tone="pos" title="Everything below is running">Every line has a recent record.</x-ds.alert>@endif

    <section class="op-block" aria-labelledby="checks-h">
        <h2 id="checks-h">Queues, payments and search</h2>
        <x-ds.table caption="Platform checks">
            <x-slot:head><tr><th scope="col">Check</th><th scope="col">Now</th><th scope="col">State</th><th scope="col">What it means</th></tr></x-slot:head>
            @foreach ($checks as $c)
                <tr>
                    <th scope="row" data-label="Check">{{ $c['label'] }}</th>
                    <td data-label="Now" class="op-num">{{ $c['value'] }}</td>
                    <td data-label="State"><x-op.state :state="$c['state']" /></td>
                    <td data-label="What it means">{{ $c['note'] }}</td>
                </tr>
            @endforeach
        </x-ds.table>
    </section>

    <section class="op-block" aria-labelledby="tasks-h">
        <h2 id="tasks-h">Scheduled tasks</h2>
        <p class="op-muted">Each run is recorded by the scheduler. A task is late when its last recorded run is older than its schedule allows. The scheduler needs the server's one-minute cron entry.</p>
        <x-ds.table caption="Scheduled tasks">
            <x-slot:head><tr><th scope="col">Task</th><th scope="col">Runs</th><th scope="col">Last run</th><th scope="col">State</th><th scope="col">What it means</th></tr></x-slot:head>
            @foreach ($tasks as $t)
                <tr>
                    <th scope="row" data-label="Task"><span class="op-code">{{ $t['task'] }}</span></th>
                    <td data-label="Runs">{{ $t['expression'] }}</td>
                    <td data-label="Last run">{{ $t['last_at'] ? $t['last_at']->format('d M H:i') : 'Never recorded' }}</td>
                    <td data-label="State"><x-op.state :state="$t['state']" /></td>
                    <td data-label="What it means">{{ $t['note'] }}</td>
                </tr>
            @endforeach
        </x-ds.table>
    </section>
</x-operator-layout>
