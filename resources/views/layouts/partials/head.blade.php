{{--
  Shared <head> for every layout. Variables: $title (the page's own name; the app name is appended), $description, $robots ('noindex' for signed-in areas), $vite (entry list), $dotUiSwitcher (default false; true in the account and the desks), $dotUi (default true; false on pages with no header search or switcher: sign-in, the packing slip).
  The inline script puts class "light" or "dark" on <html> before first paint: the saved choice, else the system's; the toggle itself lives in resources/js/app.js.
--}}
@php
    $appName = config('app.name', 'Dot.Emall');
    // A slot arrives already escaped; decode it once so the single {{ }} below does not escape an ampersand twice.
    $plainTitle = trim(html_entity_decode(strip_tags((string) ($title ?? '')), ENT_QUOTES | ENT_HTML5));
    $fullTitle = $plainTitle !== '' ? $plainTitle.' | '.$appName : $appName;
    $plainDescription = trim(html_entity_decode(strip_tags((string) ($description ?? '')), ENT_QUOTES | ENT_HTML5));
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
@if ($dotUi ?? true)
{{-- Shared Dot UI (R-51): the header search pill and the platform switcher, served from this site's own public/dot-ui ('self', no inline style or script).
     The switcher's files load only where the switcher is drawn: the signed-in account and the seller and operator desks ($dotUiSwitcher). The public shop never draws it, so a catalogue page never asks InfoDot. --}}
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-fonts.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
@if (($dotUiSwitcher ?? false) && \App\Support\Navigation::viewer()['user'])
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher-fonts.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
<script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer></script>
@endif
<script src="{{ asset('dot-ui/js/dot-search.js') }}" defer></script>
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
