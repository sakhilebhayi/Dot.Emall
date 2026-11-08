<x-auth-layout>
    <x-slot:title>Reset your password</x-slot:title>
    <x-slot:heading>Forgot your password?</x-slot:heading>
    <x-slot:lead>Tell us your email address and we will send a link that lets you choose a new one.</x-slot:lead>

    <x-validation-errors />
    @session('status')<x-ds.alert tone="pos">{{ $value }}</x-ds.alert>
@endsession

    <form method="POST" action="{{ route('password.email') }}" class="ds-stack" style="--stack: var(--s-4);">
        @csrf
        <x-ds.input name="email" label="Email" type="email" required autofocus autocomplete="username" />
        <x-ds.button type="submit">Email me a reset link</x-ds.button>
        <a href="{{ route('login') }}">Back to sign in</a>
    </form>
</x-auth-layout>
