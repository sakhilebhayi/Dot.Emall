<x-auth-layout>
    <x-slot:title>Sign in</x-slot:title>
    <x-slot:heading>Sign in to Dot.Emall</x-slot:heading>
    <x-slot:lead>Accounts come from InfoDot. Sign in with InfoDot to buy, sell and follow your orders.</x-slot:lead>

    <x-validation-errors />
    @session('status')<x-ds.alert tone="info">{{ $value }}</x-ds.alert>
@endsession

    <x-ds.button :href="config('ecosystem.platforms.infodot.url', 'https://infodot.co.za')" size="lg" block icon="shield-check">Sign in with InfoDot</x-ds.button>
    <p class="ds-muted" style="font-size: var(--text-sm);">InfoDot opens first. Choose Dot.Emall there and you arrive here signed in.</p>

    <p class="ds-divider"><span>Or use your Emall email and password</span></p>

    <form method="POST" action="{{ route('login') }}" class="ds-stack" style="--stack: var(--s-4);">
        @csrf
        <x-ds.input name="email" label="Email" type="email" required autofocus autocomplete="username" />
        <x-ds.input name="password" label="Password" type="password" required autocomplete="current-password" />
        <x-ds.checkbox name="remember" label="Keep me signed in on this device" />
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--s-3);">
            <x-ds.button type="submit" variant="quiet">Sign in with password</x-ds.button>
            @if (Route::has('password.request'))<a href="{{ route('password.request') }}">Forgot your password?</a>@endif
        </div>
    </form>
</x-auth-layout>
