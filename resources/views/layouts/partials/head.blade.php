{{--
  Shared <head> for every layout. Variables: $title (the page's own name; the app name is appended), $description, $robots ('noindex' for signed-in areas), $vite (entry list).
  The inline script puts class "light" or "dark" on <html> before first paint: the saved choice, else the system's; the toggle itself lives in resources/js/app.js.
--}}
@php
    $appName = config('app.name', 'Dot.Emall');
    $plainTitle = trim(strip_tags((string) ($title ?? '')));
    $fullTitle = $plainTitle !== '' ? $plainTitle.' | '.$appName : $appName;
    $plainDescription = trim(strip_tags((string) ($description ?? '')));
    $nonce = csp_nonce();
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="{{ \App\Support\Shell::THEME_COLOR_LIGHT }}" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="{{ \App\Support\Shell::THEME_COLOR_DARK }}" media="(prefers-color-scheme: dark)">
@if ($plainDescription !== '')<meta name="description" content="{{ $plainDescription }}">@endif
@if (! empty($robots))<meta name="robots" content="{{ $robots }}">@endif
<title>{{ $fullTitle }}</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="preload" as="font" type="font/woff2" href="{{ asset('fonts/schibsted-grotesk-var-latin.woff2') }}" crossorigin>
<script @if ($nonce) nonce="{{ $nonce }}" @endif>
    (function () {
        try {
            var saved = localStorage.getItem('emall-theme');
            var mode = (saved === 'dark' || saved === 'light') ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.classList.add(mode);
        } catch (e) {}
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
