<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains and preload are deliberately
    | left out: many sibling platforms share the parent domain and each sets its own. 15552000 is 180 days.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),

    /*
    | The Content-Security-Policy is ENFORCED by default: a nonce-based policy with no third party, run against every page in a real browser with no
    | violations (docs/R43_DECISIONS_SHELL.md). Set SECURITY_CSP_REPORT_ONLY=true to turn the same policy into report-only without a deploy (the browser then
    | reports to /csp-report and blocks nothing), for example while a new page is being built.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
];
