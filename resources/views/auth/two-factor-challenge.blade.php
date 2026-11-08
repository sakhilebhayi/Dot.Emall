<x-auth-layout>
    <x-slot:title>Two-factor sign-in</x-slot:title>
    <x-slot:heading>Confirm it is you</x-slot:heading>

    <div x-data="{ recovery: false }" class="ds-stack" style="--stack: var(--s-4);">
        <p x-show="! recovery">Enter the code from your authenticator app.</p>
        <p x-cloak x-show="recovery">Enter one of your emergency recovery codes.</p>

        <x-validation-errors />

        <form method="POST" action="{{ route('two-factor.login') }}" class="ds-stack" style="--stack: var(--s-4);">
            @csrf
            <div x-show="! recovery"><x-ds.input name="code" id="code" label="Code" inputmode="numeric" autofocus x-ref="code" autocomplete="one-time-code" /></div>
            <div x-cloak x-show="recovery"><x-ds.input name="recovery_code" id="recovery_code" label="Recovery code" x-ref="recovery_code" autocomplete="one-time-code" /></div>
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--s-3);">
                <x-ds.button type="submit">Sign in</x-ds.button>
                <button type="button" class="ds-btn ds-btn--link" x-show="! recovery" x-on:click="recovery = true; $nextTick(() => { $refs.recovery_code.focus() })">Use a recovery code</button>
                <button type="button" class="ds-btn ds-btn--link" x-cloak x-show="recovery" x-on:click="recovery = false; $nextTick(() => { $refs.code.focus() })">Use an authentication code</button>
            </div>
        </form>
    </div>
</x-auth-layout>
