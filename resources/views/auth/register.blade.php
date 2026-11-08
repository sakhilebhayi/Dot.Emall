<x-auth-layout>
    <x-slot:title>Create an account</x-slot:title>
    <x-slot:heading>Create an account</x-slot:heading>
    <x-slot:lead>Accounts come from InfoDot. If you were sent here to register, use this form.</x-slot:lead>

    <x-validation-errors />
    <form method="POST" action="{{ route('register') }}" class="ds-stack" style="--stack: var(--s-4);">
        @csrf
        <x-ds.input name="name" label="Name" required autofocus autocomplete="name" />
        <x-ds.input name="email" label="Email" type="email" required autocomplete="username" />
        <x-ds.input name="password" label="Password" type="password" required autocomplete="new-password" />
        <x-ds.input name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
        @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
            <label class="ds-choice" for="terms"><input type="checkbox" id="terms" name="terms" required><span class="ds-choice__text"><span class="ds-choice__label">I agree to the <a href="{{ route('terms.show') }}" target="_blank">terms of service</a> and <a href="{{ route('policy.show') }}" target="_blank">privacy policy</a></span></span></label>
        @endif
        <x-ds.button type="submit">Create account</x-ds.button>
        <a href="{{ route('login') }}">Already registered? Sign in</a>
    </form>
</x-auth-layout>
