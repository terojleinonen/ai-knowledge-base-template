#!/bin/sh
# Container entrypoint: prepare the app, optionally run background processes, then serve.
set -e

# Not `optimize`: that also caches Blade views, and this API-only app has none.
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan migrate --force

if [ "${KB_DEMO_ENABLED:-false}" = "true" ]; then
    # Idempotent: only embeds the demo documents when they're missing or the model changed.
    php artisan kb:demo:prepare || echo "WARNING: demo preparation failed; guest sessions will retry it."
fi

# Free hosting tiers have no separate worker service, so the web container can run
# the queue worker and scheduler itself. On paid plans, run them as separate services.
if [ "${RUN_WORKER_IN_WEB:-false}" = "true" ]; then
    (while true; do php artisan queue:work --tries=3 --timeout=900 --max-time=3600 --sleep=5 || sleep 5; done) &
    php artisan schedule:work &
fi

exec frankenphp php-server --root public --listen ":${PORT:-8080}"
