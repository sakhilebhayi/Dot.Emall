{{--
  <x-error-layout>: the frame of every error page (403, 404, 419, 429, 500, 503). It is STANDALONE and safe when the database or the session is down: it reads no session, no user, no
  database and no Livewire. Styles come from the built stylesheet when it exists; without it the page is still plain, readable HTML.
  Props: code (required, the HTTP status), heading (required), message (required), hint=null (one more sentence). Slot: actions (links; the default offers the shop and sign-in).
--}}
@props(['code', 'heading', 'message', 'hint' => null])
@php
    $manifest = public_path('build/manifest.json');
    $hasBuild = is_file($manifest);
    $nonce = csp_nonce();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title>{{ $heading }} | Dot.Emall</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <script @if ($nonce) nonce="{{ $nonce }}" @endif>(function(){try{var s=localStorage.getItem('emall-theme');var m=(s==='dark'||s==='light')?s:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.classList.add(m);}catch(e){}})();</script>
    @if ($hasBuild)@vite(['resources/css/app.css', 'resources/js/app.js'])@endif
</head>
<body class="ds-ctx-auth">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-auth">
        <div>
            <div class="ds-awning" aria-hidden="true"></div>
            <div class="ds-container ds-auth__top"><x-ds.logo :href="url('/')" /></div>
        </div>
        <main id="main" class="ds-container ds-auth__main" tabindex="-1">
            <div class="ds-auth__card">
                <div class="ds-label">
                    <div class="ds-label__main">
                        <span class="ds-label__from">Error {{ $code }}</span>
                        <p class="ds-error-code" aria-hidden="true">{{ $code }}</p>
                        <h1>{{ $heading }}</h1>
                    </div>
                    <div class="ds-label__perf" aria-hidden="true"></div>
                    <div class="ds-auth__body">
                        <p>{{ $message }}</p>
                        @if ($hint)<p class="ds-muted">{{ $hint }}</p>@endif
                        <div class="ds-page-header__actions">
                            @if (isset($actions) && trim((string) $actions) !== ''){{ $actions }}@else
                                <x-ds.button :href="url('/')" icon="home">Back to the shop</x-ds.button>
                                <x-ds.button :href="url('/login')" variant="quiet">Sign in</x-ds.button>
                            @endif
                        </div>
                    </div>
                </div>
                <p class="ds-error-eco">Dot.Emall is part of <a href="{{ config('ecosystem.platforms.infodot.url', 'https://infodot.co.za') }}">InfoDot and the rest of the Dot Ecosystem</a>.</p>
            </div>
        </main>
        <div class="ds-container ds-auth__foot"><span>If this keeps happening, tell the person who sent you the link.</span></div>
    </div>
</body>
</html>
