<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is REPORT-ONLY: the browser reports what it would have blocked to /csp-report and blocks nothing.
    | Set SECURITY_CSP_REPORT_ONLY=false to enforce the same policy without a deploy, once the reports have been reviewed.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),

    /*
    | Comma separated host names this app answers for (TRUSTED_HOSTS). Empty means: the host of APP_URL. A request for any
    | other Host is refused with a 400 (production only; local and testing are never pinned, and /up is exempt so a
    | health probe by IP still works). Without this, a forged Host header is copied into every URL the app builds: the
    | password-reset link in the email and the callback Paystack sends the buyer back to. List every alias the app is
    | really reached on, for example "emall.infodot.co.za,emall.infodot.app".
    */
    'trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),
];
