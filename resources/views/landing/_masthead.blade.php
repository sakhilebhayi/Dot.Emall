{{--
  The top of the landing (R-50): the owner's ORIGINAL logo file, unaltered (public/images/logo.png, and logo-light.png on the dark theme), the welcome
  from the signed-in person's own name and company. The way back to InfoDot is the header's platform switcher (R-51: the InfoDot tile, and "See all platforms" in every state),
  which replaced the two-link nav that used to sit here. Variables: $firstName, $company (null when no company is linked), $role ('for' | 'running': how the company line reads).
--}}
<div class="ld-mast">
    <span class="ld-logo">
        <img class="ld-logo__img ld-logo__img--light" src="{{ asset(\App\Support\Landing::LOGO) }}" alt="Dot.Emall" width="1181" height="886" decoding="async">
        <img class="ld-logo__img ld-logo__img--dark" src="{{ asset(\App\Support\Landing::LOGO_ON_DARK) }}" alt="Dot.Emall" width="1181" height="886" decoding="async">
    </span>
    <p class="ld-welcome">
        <strong>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.</strong>
        @if ($company)<span>{{ $role === 'running' ? 'You run' : 'You are here with' }} {{ $company }}.</span>@endif
    </p>
</div>
