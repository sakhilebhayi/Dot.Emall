{{--
  ds.logo: the Dot.Emall mark and wordmark (public/images/emall-*.png, cropped from the owner's logo). The light and dark wordmarks swap with the theme.
  Props: href='/' (the home link), word=true (show the wordmark beside the mark).
--}}
@props(['href' => '/', 'word' => true])
<a href="{{ $href }}" {{ $attributes->class('ds-logo') }} aria-label="Dot.Emall, home">
    <img class="ds-logo__mark" src="{{ asset('images/emall-mark.png') }}" alt="" width="44" height="44">
    @if ($word)
        <img class="ds-logo__word ds-logo__light" src="{{ asset('images/emall-wordmark.png') }}" alt="" width="104" height="24">
        <img class="ds-logo__word ds-logo__dark" src="{{ asset('images/emall-wordmark-light.png') }}" alt="" width="104" height="24">
    @endif
</a>
