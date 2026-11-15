{{--
  The shared Dot UI assets (dot-ui 0.6.1), all from this site's own public/dot-ui: no CDN, no inline style or script ('self' only).
  Variables: $header (true: the one minified bundle dot-ui.min.css, which holds Roboto, the type scale, the tokens and the search, header and switcher styles, so it
  is ONE request and the first stylesheet; false: Roboto and the type scale only, for the packing slip). The switcher's styles ride in the bundle (about 3 kB gzip), but its
  script and markup appear only where the switcher is drawn (the signed-in account, the desks and the signed-in storefront), so a guest on a catalogue page never asks InfoDot.
  The Roboto file is not preloaded (dot-ui 0.6.1 PERFORMANCE.md: a preload competes with the page's own largest paint, which here is a dashboard numeral or a product photo).
--}}
@if ($header ?? true)
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@else
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-roboto.min.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-type.min.css') }}">
@endif
<script src="{{ asset('dot-ui/js/dot-theme-boot.min.js') }}"></script>
