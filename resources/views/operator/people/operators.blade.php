<x-operator-layout>
    <x-slot:title>Operators</x-slot:title>
    <x-slot:heading>Operators</x-slot:heading>
    <x-slot:lead>The people who can run the marketplace. The first operator is created on the server with <span class="op-code">php artisan emall:operator person@example.com</span>. From here you can make an existing, verified account an operator, or remove one. Both need a reason and are written to the audit trail.</x-slot:lead>
    <x-slot:breadcrumbs><x-ds.breadcrumbs :items="[['label' => 'People', 'href' => route('operator.people.index')], ['label' => 'Operators']]" /></x-slot:breadcrumbs>

    <div class="op-split">
        <section class="op-block" aria-labelledby="ops-h">
            <h2 id="ops-h">Operators now ({{ $operators->count() }})</h2>
            <ul class="op-items">
                @foreach ($operators as $op)
                    <li class="op-item">
                        <div class="op-item__head"><strong>{{ $op->name }}@if ($op->id === auth()->id()) <x-ds.badge icon="user">You</x-ds.badge>@endif</strong><span class="op-muted">{{ $op->email }}</span></div>
                        @if ($op->id !== auth()->id() && $operators->count() > 1)
                            <x-op.decide :bag="'revoke-'.$op->id" :action="route('operator.team.revoke', $op)" :summary="'Remove '.$op->name" submit="Remove operator access" tone="danger" label="Why access is removed" hint="Kept in the audit trail." />
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
        <aside class="op-side" aria-label="Make an operator">
            <section class="op-block">
                <h2>Make someone an operator</h2>
                @php($bag = $errors->getBag('grant'))
                <form method="POST" action="{{ route('operator.team.grant') }}" class="ds-stack">
                    @csrf
                    <x-ds.input label="Their e-mail address" name="email" type="email" id="grant-email" :value="session('open_form') === 'grant' ? old('email') : ''" :error="$bag->first('email') ?: ''" hint="They must already have signed in through InfoDot and verified their e-mail." required />
                    <x-ds.textarea label="Why" name="reason" id="grant-reason" rows="2" :value="session('open_form') === 'grant' ? old('reason') : ''" :error="$bag->first('reason') ?: ''" required />
                    <x-ds.button type="submit">Make operator</x-ds.button>
                </form>
            </section>
            <section class="op-block">
                <h2>Recent changes</h2>
                @forelse ($history as $e)<p class="op-muted"><strong>{{ $e->actor_name }}</strong> {{ lcfirst(app(\App\Services\Operator\OperatorAudit::class)->label($e->action)) }}: {{ $e->subject_label }}, <x-op.age :at="$e->created_at" /> ago. {{ $e->reason }}</p>@empty<p class="op-muted">None yet.</p>@endforelse
            </section>
        </aside>
    </div>
</x-operator-layout>
