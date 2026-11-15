{{--
  <x-auth-layout>: sign-in, password reset, two-factor, email verification, and the legal pages' frame. One centred label on the page, the awning above, and (R-52) the shared dot-ui header with the shop's search above it.
  Slots: title (REQUIRED, the <title>), heading (the page's one <h1>, shown on the label), lead (one sentence under it), default (the form), footer (extra links under the label).
  Props: wide=false (a wider label for the legal pages), label=true (draw the label card; pass :label="false" for a bare column).
--}}
@props(['wide' => false, 'label' => true])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="emall-theme" data-dh-theme-default="system">
<head>
    @include('layouts.partials.head', ['title' => $title ?? null, 'description' => $description ?? null, 'robots' => 'noindex'])
    @livewireStyles(['nonce' => csp_nonce()])
</head>
<body class="ds-ctx-auth">
    <a class="ds-skip" href="#main">Skip to main content</a>
    <div class="ds-auth">
        <div>
            @include('layouts.partials.bare-header')
        </div>
        <main id="main" class="ds-container ds-auth__main" tabindex="-1">
            <div class="ds-auth__card" @if ($wide) style="width: min(100%, 48rem);" @endif>
                @if ($label)
                    <div class="ds-label ds-auth__label">
                        <div class="ds-label__main">
                            @isset($heading)<h1>{{ $heading }}</h1>@endisset
                            @isset($lead)<p class="ds-page-header__lead">{{ $lead }}</p>@endisset
                        </div>
                        <div class="ds-label__perf" aria-hidden="true"></div>
                        <div class="ds-auth__body">{{ $slot }}</div>
                    </div>
                @else
                    @isset($heading)<h1>{{ $heading }}</h1>@endisset
                    {{ $slot }}
                @endif
                @isset($footer)<div style="margin-top: var(--s-4);">{{ $footer }}</div>@endisset
            </div>
        </main>
        <footer class="ds-container ds-auth__foot">
            <a href="{{ route('policy.show') }}">Privacy policy</a>
            <a href="{{ route('terms.show') }}">Terms of service</a>
            <a href="{{ route('cookies') }}">Cookie policy</a>
            <span>Accounts come from InfoDot.</span>
        </footer>
    </div>
    <x-ds.toasts />
    @livewireScripts(['nonce' => csp_nonce()])
</body>
</html>
