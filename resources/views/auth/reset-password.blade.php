<x-auth-layout>
    <x-slot:title>Choose a new password</x-slot:title>
    <x-slot:heading>Choose a new password</x-slot:heading>

    <x-validation-errors />
    <form method="POST" action="{{ route('password.update') }}" class="ds-stack" style="--stack: var(--s-4);">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-ds.input name="email" label="Email" type="email" :value="old('email', $request->email)" required autofocus autocomplete="username" />
        <x-ds.input name="password" label="New password" type="password" required autocomplete="new-password" />
        <x-ds.input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        <x-ds.button type="submit">Save new password</x-ds.button>
    </form>
</x-auth-layout>
