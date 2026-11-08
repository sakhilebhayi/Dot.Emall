{{--
  ds.logo: the Dot.Emall mark (public/images/emall-mark.png, cropped from the owner's logo) and a live-type wordmark.
  The wordmark is set in Schibsted Grotesk at 800: "dot" in ink, a punched awning-yellow full stop, "emall" in Stamp indigo. The first version used the thin
  light-weight picture of the logo, which read "email" at reading size, and its yellow letters were 1.6:1 on the page ground (R-43 polish, D-180).
  The yellow now lives in the mark and in the full stop, which carries an ink ring so it holds 3:1 on the ground; text is ink and indigo only (both AA in both themes).
  Props: href='/' (the home link), word=true (show the wordmark beside the mark).
--}}
@props(['href' => '/', 'word' => true])
<a href="{{ $href }}" {{ $attributes->class('ds-logo') }} aria-label="Dot.Emall, home">
    <img class="ds-logo__mark" src="{{ asset('images/emall-mark.png') }}" alt="" width="44" height="44">
    @if ($word)
        <span class="ds-logo__word" aria-hidden="true"><span class="ds-logo__dot">dot</span><span class="ds-logo__stop"></span><span class="ds-logo__emall">emall</span></span>
    @endif
</a>
