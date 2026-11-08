<x-auth-layout>
    <x-slot:title>Verify your email</x-slot:title>
    <x-slot:heading>Check your email</x-slot:heading>
    <x-slot:lead>Before you continue, open the link we just emailed you to verify your address. If it did not arrive, we will send another.</x-slot:lead>

    @if (session('status') == 'verification-link-sent')
        <x-ds.alert tone="pos">A new verification link has been sent to the email address in your profile.</x-ds.alert>
    @endif

    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: var(--s-3);">
        <form method="POST" action="{{ route('verification.send') }}">@csrf<x-ds.button type="submit">Resend the verification email</x-ds.button></form>
        <a href="{{ route('profile.show') }}">Edit profile</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="ds-btn ds-btn--link">Sign out</button></form>
    </div>
</x-auth-layout>
