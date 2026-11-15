{{--
  The shared Dot UI scripts (dot-ui 0.6.1), deferred, printed AFTER the app's own entry (@vite) in the head. Deferred scripts run in document order, so the nav script used to hold the page's own
  script (the dashboards' count-up and chart) back until it had arrived: on a slow connection that moved the dashboards' largest paint by about a second. The app's script does not need any of these at
  load: it reads window.DotNav inside event handlers and window.DotSearchResults after DOMContentLoaded. Variables: $header; $switcher (the switcher script only where the switcher is drawn: it is
  inside dot-ui-core.min.js, which is harmless without a switcher on the page, but the public shop keeps the separate decision visible); $attach (true on the desks: their search calls
  DotSearchResults.attach() from resources/js/app.js, so they load dot-ui.min.js, where the results controller is inside; everywhere else dot-ui-core.min.js loads the controller lazily on first focus).
--}}
@if ($header ?? true)
@if ($attach ?? false)
<script src="{{ asset('dot-ui/js/dot-ui.min.js') }}" defer></script>
@else
<script src="{{ asset('dot-ui/js/dot-ui-core.min.js') }}" defer></script>
@endif
@endif
