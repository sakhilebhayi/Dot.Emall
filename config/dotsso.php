<?php

use App\Sso\EmallProvisioner;

/*
 * Dot.Emall's own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false) and the session guard (the package defaults it to `web`), comes from the package.
 */
return [
    'provisioner' => EmallProvisioner::class,

    // Emall has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link is only ever a same-origin path (the package enforces that). The deny-list names the machine and
    // sign-in doors so a link can never point at them. The public shop (storefront, products, cart, checkout, guest
    // orders) never needs a sign-in; a person who chooses to sign in from one of those pages comes back to it.
    'deep_link' => [
        'allow' => ['#^/#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm|webhooks|api|livewire|csp-report|up|guest/orders)(/|\?|$)#i'],
    ],

    // Nothing extra is exempt. Guest checkout, magic-link orders and the public shop are not sign-in pages, so they are
    // never intercepted; the Paystack webhook, Sanctum, /api, /csp-report and /up are fixed machine paths in the package.
    'allow_paths' => [],
];
