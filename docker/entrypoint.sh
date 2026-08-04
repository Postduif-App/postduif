#!/bin/sh
set -e

##
# Runs before every process in the image — the web server, the queue worker,
# the scheduler and Reverb all come through here. Whatever it does has to be
# safe to do four times at once, which is why migrations are not here but in
# their own one-shot service.
##

cd /app

# In development the source arrives as a bind mount, which lands on top of
# everything the build created at /app. The directories Laravel writes to have
# to be re-created after that mount, not before it.
mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ "$APP_ENV" = "production" ]; then
    # A missing key in production is a stop, not something to paper over: this
    # install encrypts webhook tokens, and generating a fresh key on every boot
    # would quietly turn the stored ones into noise.
    if [ -z "$APP_KEY" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
        echo "APP_KEY is empty. Generate one with 'php artisan key:generate --show' and put it in your .env." >&2
        exit 1
    fi

    # Config, routes, events and views compiled into bootstrap/cache. Done here
    # rather than in the image because caching the config freezes the
    # environment as it was at that moment, and at build time it was not yet
    # the environment this container runs in.
    if [ "${SKIP_OPTIMIZE:-0}" != "1" ]; then
        php artisan optimize --no-interaction
    fi
else
    if [ ! -f .env ] && [ -f .env.example ]; then
        cp .env.example .env
    fi

    if [ -f .env ] && ! grep -qE '^APP_KEY=.+' .env; then
        php artisan key:generate --force --no-interaction
    fi
fi

# The attachment disk is private, but Filament and anything under
# storage/app/public are served through this symlink. A bind mount hides the
# one the build made, so it is checked here rather than baked in.
if [ ! -e public/storage ]; then
    php artisan storage:link --quiet || true
fi

exec docker-php-entrypoint "$@"
