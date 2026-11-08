{{--
  Sign in. R-43 polish #25 (D-189): one ticket, two halves. The left half is the way the platform wants you to sign in (InfoDot, the primary action); the right half is the Emall password for
  accounts that have one, with a clear secondary button, the "Forgot" link on its own line beside "Keep me signed in", and a password reveal. Phones stack the halves with a horizontal perforation.
--}}
<x-auth-layout :wide="true">
    <x-slot:title>Sign in</x-slot:title>
    <x-slot:heading>Sign in to Dot.Emall</x-slot:heading>
    <x-slot:lead>Accounts come from InfoDot. Sign in to buy, sell and follow your orders.</x-slot:lead>

    <x-validation-errors />
    @session('status')<x-ds.alert tone="info">{{ $value }}</x-ds.alert>
@endsession

    <div class="ds-signin">
        <section class="ds-signin__half" aria-labelledby="signin-infodot-h">
            <h2 id="signin-infodot-h">With InfoDot</h2>
            <p class="ds-muted">InfoDot opens first. Choose Dot.Emall there and you arrive here signed in.</p>
            <x-ds.button :href="config('ecosystem.platforms.infodot.url', 'https://infodot.co.za')" size="lg" block icon="shield-check">Sign in with InfoDot</x-ds.button>
        </section>

        <section class="ds-signin__half ds-signin__half--stub" aria-labelledby="signin-password-h">
            <h2 id="signin-password-h">With your Emall password</h2>
            <form method="POST" action="{{ route('login') }}" class="ds-stack" style="--stack: var(--s-4);">
                @csrf
                <x-ds.input name="email" label="Email" type="email" required autofocus autocomplete="username" />
                <x-ds.input name="password" label="Password" type="password" required autocomplete="current-password" :reveal="true" />
                <div class="ds-signin__row">
                    <x-ds.checkbox name="remember" label="Keep me signed in on this device" />
                    @if (Route::has('password.request'))<a href="{{ route('password.request') }}">Forgot your password?</a>@endif
                </div>
                <x-ds.button type="submit" variant="quiet" size="lg" block>Sign in with password</x-ds.button>
            </form>
        </section>
    </div>
</x-auth-layout>
