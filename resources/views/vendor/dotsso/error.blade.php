{{-- R-54: every sign-in failure ends here, in words, with one next step and no automatic redirect. --}}
<x-auth-layout>
    <x-slot:title>{{ $headline }}</x-slot:title>
    <x-slot:heading>{{ $headline }}</x-slot:heading>
    <x-slot:lead>Signing in to Dot.Emall through InfoDot did not finish.</x-slot:lead>

    <x-ds.alert tone="danger">{{ $body }}</x-ds.alert>

    <div class="ds-claim__row">
        @if ($retry)<x-ds.button :href="$retryUrl" size="lg">Start again</x-ds.button>@endif
        <x-ds.button :href="$homeUrl" variant="quiet" size="lg">Back to Dot.Emall</x-ds.button>
    </div>
    <p class="ds-muted">Reference: {{ $code }}</p>
</x-auth-layout>
