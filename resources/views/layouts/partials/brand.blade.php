{{--
  The Dot.Emall mark and live-type wordmark, inside the shared header's brand link (<a class="dh-brand">, which carries the accessible name). R-52: the wordmark is live type in Roboto 800 (the platform's previous text face is gone); "dot" in ink, the punched awning-yellow full stop, "emall" in Stamp indigo. The mark is the owner's original logo artwork, never redrawn. Variable: $word (default true; the desks show the mark alone).
--}}
<img class="ds-logo__mark" src="{{ asset('images/emall-mark.png') }}" alt="" width="44" height="44">
@if ($word ?? true)<span class="dh-brand__name"><span class="ds-logo__word" aria-hidden="true"><span class="ds-logo__dot">dot</span><span class="ds-logo__stop"></span><span class="ds-logo__emall">emall</span></span></span>@endif
