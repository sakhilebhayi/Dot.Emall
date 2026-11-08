<x-auth-layout>
    <x-slot:title>Confirm your password</x-slot:title>
    <x-slot:heading>Confirm your password</x-slot:heading>
    <x-slot:lead>This is a secure area. Please confirm your password before continuing.</x-slot:lead>

    <x-validation-errors />
    <form method="POST" action="{{ route('password.confirm') }}" class="ds-stack" style="--stack: var(--s-4);">
        @csrf
        <x-ds.input name="password" label="Password" type="password" required autofocus autocomplete="current-password" />
        <x-ds.button type="submit">Confirm</x-ds.button>
    </form>
</x-auth-layout>
