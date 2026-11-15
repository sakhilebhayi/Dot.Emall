{{--
  Shared <head> for every layout. Variables: $title (the page's own name; the app name is appended), $description, $robots ('noindex' for signed-in areas), $vite (entry list), $dotUiSwitcher (default false; true in the account and the desks), $dotUiAttach (default false; true on the desks, whose search calls DotSearchResults.attach()), $dotUi (default true; false only on the packing slip, which has no header).
  R-52: Roboto and the shared header come from this site's own public/dot-ui (dot-ui 0.6.1). dot-theme-boot.min.js (an external file, so no inline script) puts class "dark" (and data-theme) on <html> before first paint from the saved choice (key emall-theme), else the system's.
--}}
@php
    $appName = config('app.name', 'Dot.Emall');
    // A slot arrives already escaped; decode it once so the single {{ }} below does not escape an ampersand twice.
    $plainTitle = trim(html_entity_decode(strip_tags((string) ($title ?? '')), ENT_QUOTES | ENT_HTML5));
    $fullTitle = $plainTitle !== '' ? $plainTitle.' | '.$appName : $appName;
    $plainDescription = trim(html_entity_decode(strip_tags((string) ($description ?? '')), ENT_QUOTES | ENT_HTML5));
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
@include('layouts.partials.dot-assets', ['header' => $dotUi ?? true, 'switcher' => ($dotUiSwitcher ?? false) && \App\Support\Navigation::viewer()['user']])
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('layouts.partials.dot-scripts', ['header' => $dotUi ?? true, 'attach' => $dotUiAttach ?? false, 'switcher' => ($dotUiSwitcher ?? false) && \App\Support\Navigation::viewer()['user']])
