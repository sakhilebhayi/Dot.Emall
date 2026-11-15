{{--
  The shared Dot UI assets (dot-ui 0.6.0), all from this site's own public/dot-ui: no CDN, no inline style or script ('self' only).
  Roboto and its type scale load on every page that includes this (the packing slip and the standalone 500/503 page included). Variables: $header (the header, search
  pill and menu scripts), $switcher (the platform switcher's files, only where it is drawn: the signed-in account, the desks and the signed-in storefront; a guest on a
  catalogue page never loads it, so the public shop never asks InfoDot).
--}}
<link rel="preload" as="font" type="font/woff2" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" crossorigin>
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.css') }}">
<script src="{{ asset('dot-ui/js/dot-theme-boot.js') }}"></script>
@if ($header ?? true)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-header.css') }}">
@if ($switcher ?? false)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
@endif
@endif
