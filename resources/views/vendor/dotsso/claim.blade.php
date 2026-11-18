{{-- R-54: the claim page, on the Shelf Edge label. Shown when an Emall account already uses the email address of the InfoDot
     sign-in. Nothing on the account is replaced; the code proves the address. Variables come from the shared package. --}}
<x-auth-layout>
    <x-slot:title>Confirm it is you</x-slot:title>
    <x-slot:heading>Confirm it is you</x-slot:heading>
    <x-slot:lead>A Dot.Emall account already uses {{ $maskedEmail }}. We sent a {{ $codeLength }}-digit code to that address.</x-slot:lead>

    @if (session('dotsso_status'))
        <x-ds.alert tone="info">{{ session('dotsso_status') }}</x-ds.alert>
    @endif

    <form method="POST" action="{{ $verifyUrl }}" class="ds-stack ds-claim" style="--stack: var(--s-4);" novalidate>
        @csrf
        <div aria-live="polite">
            <x-ds.input label="Code from the email" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code"
                maxlength="{{ $codeLength }}" required autofocus class="ds-claim__code"
                hint="It works for {{ $minutes }} minutes. You have {{ $maxAttempts }} tries." />
        </div>
        <p class="ds-muted">Your orders, store and payouts stay exactly as they are. Nothing about the account is replaced.</p>
        <x-ds.button type="submit" size="lg" block>Confirm and continue</x-ds.button>
    </form>

    <div class="ds-claim__row">
        <form method="POST" action="{{ $resendUrl }}">@csrf<x-ds.button type="submit" variant="quiet">Send a new code</x-ds.button></form>
        <form method="POST" action="{{ $cancelUrl }}">@csrf<x-ds.button type="submit" variant="quiet">Cancel</x-ds.button></form>
    </div>
</x-auth-layout>
