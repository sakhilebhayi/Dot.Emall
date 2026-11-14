{{--
  dx.arrive (R-51): the top of every dashboard. The owner's ORIGINAL logo file (unaltered), one true sentence about the state of things as the h1, a supporting sentence that
  carries the welcome, and the ONE primary action (data-primary-action: exactly one per page). The art is drawn from the product's own label vocabulary and is decorative.
  Props: headline, sentence, primary (label, href), art ('seller' | 'buyer' | 'operator'), firstName=null, company=null, role='for' | 'running' (how the company line reads).
--}}
@props(['headline', 'sentence', 'primary', 'art' => 'seller', 'firstName' => null, 'company' => null, 'role' => 'for'])
<section class="dx-arrive" aria-labelledby="dx-h1">
    <span class="dx-arrive__logo">
        <img class="dx-arrive__img dx-arrive__img--light" src="{{ asset(\App\Support\Landing::LOGO) }}" alt="Dot.Emall" width="1181" height="886" decoding="async">
        <img class="dx-arrive__img dx-arrive__img--dark" src="{{ asset(\App\Support\Landing::LOGO_ON_DARK) }}" alt="Dot.Emall" width="1181" height="886" decoding="async">
    </span>
    <div class="dx-arrive__text">
        <h1 id="dx-h1">{{ $headline }}</h1>
        <p>@if ($firstName)<strong>Welcome{{ $firstName !== '' ? ', '.$firstName : '' }}.</strong> @if ($company){{ $role === 'running' ? 'You run' : 'You are here with' }} {{ $company }}. @endif @endif{{ $sentence }}</p>
    </div>
    <x-dx.art :kind="$art" />
    <div class="dx-arrive__act">
        <x-ds.button :href="$primary['href']" size="lg" icon-right="arrow-right" data-primary-action="">{{ $primary['label'] }}</x-ds.button>
    </div>
</section>
