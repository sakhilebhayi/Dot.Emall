#!/bin/sh
# Signed-in smoke check with the production caches on (R-43).
#
#   sh tests/Support/signed-in-smoke.sh
#
# Builds a throwaway SQLite database, runs `php artisan route:cache && config:cache && event:cache` (what every deploy
# does), opens every page as the right person and compares the answer, then removes the caches again. It never touches
# your real database: DB_* and the session/cache/queue/mail drivers are forced for this run only, and the cached config
# is deleted on exit. APP_ENV=production, so a debug page can never hide a failure.
set -u
cd "$(dirname "$0")/../.."

WORK=$(mktemp -d)
export APP_ENV=production APP_DEBUG=false APP_URL=https://emall.smoke.test
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite" DB_URL=
export SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=log
export FILESYSTEM_DISK=local LOG_CHANNEL=stderr LOG_LEVEL=error
export PAYSTACK_SECRET_KEY= PAYSTACK_PUBLIC_KEY= ANALYTICS_INGEST_URL= ANALYTICS_INGEST_SECRET= INFODOT_SERVICE_SECRET=
touch "$DB_DATABASE"

# The guest layouts use @vite(), which needs a build manifest. A deploy builds the real one; this check only needs the
# pages to render, so when there is no build a stand-in manifest is written for the run and removed again.
STUB_BUILD=""
if [ ! -f public/build/manifest.json ]; then
    STUB_BUILD=1
    mkdir -p public/build
    printf '%s' '{"resources/css/app.css":{"file":"assets/app.css","src":"resources/css/app.css","isEntry":true},"resources/js/app.js":{"file":"assets/app.js","src":"resources/js/app.js","isEntry":true}}' > public/build/manifest.json
fi

cleanup() {
    php artisan optimize:clear >/dev/null 2>&1
    rm -rf "$WORK"
    if [ -n "$STUB_BUILD" ]; then rm -f public/build/manifest.json; rmdir public/build 2>/dev/null; fi
}
trap cleanup EXIT INT TERM

php artisan optimize:clear >/dev/null 2>&1 || exit 1
php artisan migrate --force >/dev/null || exit 1
php artisan route:cache && php artisan config:cache && php artisan event:cache || exit 1
php tests/Support/signed-in-smoke.php
