{{--
  The shared Dot UI scripts (dot-ui 0.6.0), all deferred, printed AFTER the app's own entry (@vite) in the head. Deferred scripts run in document order, so the 33 kB dot-nav.js used to hold the page's own
  script (the dashboards' count-up and chart) back until it had arrived: on a slow connection that moved the dashboards' largest paint by about a second. The app's script does not need any of these at
  load: it reads window.DotNav inside event handlers and window.DotSearchResults after DOMContentLoaded. Variables: $header, $switcher (as in dot-assets).
--}}
@if ($header ?? true)
@if ($switcher ?? false)
<script src="{{ asset('dot-ui/js/dot-switcher.js') }}" defer></script>
@endif
<script src="{{ asset('dot-ui/js/dot-search.js') }}" defer></script>
<script src="{{ asset('dot-ui/js/dot-search-results.js') }}" defer></script>
<script src="{{ asset('dot-ui/js/dot-nav.js') }}" defer></script>
@endif
