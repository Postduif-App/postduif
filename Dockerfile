# syntax=docker/dockerfile:1

##
# One image, two shapes.
#
# `build` has everything needed to produce the app — PHP, Composer and Node —
# and is thrown away. `production` keeps only the result: no Node, no Composer,
# no source of the frontend. What is not in the image does not have to be
# patched. `development` puts the tooling back, because a bind-mounted source
# tree needs a Composer and an npm to keep it up to date.
#
# The server is FrankenPHP rather than nginx plus php-fpm: it serves the static
# files and the PHP from a single process, so nothing here has to supervise two
# daemons in one container. The same image runs the queue worker, the scheduler
# and Reverb — those only differ in their command.
##

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# --- base --------------------------------------------------------------------
# Debian rather than Alpine on purpose: package.json pins the glibc builds of
# rollup, lightningcss and the Tailwind oxide binary in optionalDependencies.
# On musl npm would need the other set, and you would find out at `vite build`.
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS base

WORKDIR /app

# pdo_pgsql because DB_CONNECTION is pgsql, redis because REDIS_CLIENT is
# phpredis, gd plus exif because attachments get a webp preview conversion (see
# Message::registerMediaConversions) — gd here is built with webp, jpeg and
# freetype support. pcntl is what the queue worker and Reverb use to catch the
# signal that tells them to stop; without it neither shuts down cleanly.
#
# Still no imagick: the PDF and SVG image generators inside it also need
# spatie/pdf-to-image, which this app does not install, so they decline the file
# instead of failing on it.
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip

# Ghostscript, as a binary rather than through imagick.
#
# Every contract PDF is rewritten by it before it is stored: that is what strips
# embedded JavaScript and attached files out of a document that is about to be
# mailed to people outside the workspace, and what writes it back out as PDF 1.4
# — the version the free FPDI parser can read, and so the version the signed
# copy can be composed from. See App\Actions\Contracts\NormalisePdf.
#
# Without it the upload refuses every file. That is the deliberate failure
# direction — better a refusal while the author is still standing there than a
# contract that cannot be produced after somebody has signed it — but it does
# mean the binary is not optional in any image that serves requests.
#
# In the `base` stage rather than in `production`, so the development image and
# the queue worker have it too. It lands on /usr/bin/gs, which is on the PATH of
# every process here; config/contracts.php falls back to a bare `gs` and finds
# it. GHOSTSCRIPT_PATH is for hosts where that is not true — a Mac with Homebrew
# and php-fpm, most obviously.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ghostscript \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# Plain HTTP on a port above 1024, so the server needs neither root nor a
# capability to bind it. TLS belongs in front of this, not in it.
ENV SERVER_NAME=":8080"

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# --- toolchain ---------------------------------------------------------------
# Composer and Node, copied in rather than installed: the Debian repositories
# carry a Node too old for Vite 8, and the version is worth pinning anyway.
FROM base AS toolchain

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# --- build -------------------------------------------------------------------
FROM toolchain AS build

# Built as production, which is also how it is deployed. Wayfinder generates
# resources/js/routes from the routes that are actually registered, so anything
# the bundle imports has to be a route that exists in every environment — see
# the note on devAccounts in FortifyServiceProvider for the one place that was
# not, and how it is kept that way.
ENV APP_ENV=production

# The bundle carries these: Echo is configured from import.meta.env, so the key
# and the host the browser must connect to are decided when the assets are
# built, not when the container starts. Pass them with --build-arg, or let the
# production compose file read them from your .env.
ARG VITE_APP_NAME=Postduif
ARG VITE_REVERB_APP_KEY=
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
ENV VITE_APP_NAME=${VITE_APP_NAME} \
    VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

# The manifests first, so a change to the application code does not throw away
# the layer that installed the dependencies.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

# The writable directories are kept out of the build context (see
# .dockerignore, which refuses to carry your local logs and sessions into an
# image), so they are made here. Artisan will not run without them: the view
# compiler asks for its cache path before it does anything else.
RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs

# Wayfinder runs `php artisan` from inside the Vite build, so the autoloader and
# the package manifest have to exist before `npm run build` — this is not an
# optimisation, the frontend build fails without it.
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade

RUN npm run build

# And the same pages again, for the renderer in the `ssr` stage below. Its own
# config bundles the dependencies in rather than importing them at runtime —
# see vite.config.ssr.ts.
RUN npx vite build --ssr --config vite.config.ssr.ts

# Everything Node needed is now in public/build and bootstrap/ssr. The runtime
# gets neither.
RUN rm -rf node_modules .env

# --- production --------------------------------------------------------------
FROM base AS production

COPY docker/php-production.ini /usr/local/etc/php/conf.d/zzz-env.ini

# Not root: a web server that never installs anything has no business being able
# to. Caddy keeps its certificates and state in /data and /config.
RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --home /home/app --create-home app \
    && chown -R app:app /data /config

COPY --from=build --chown=app:app /app /app

USER app

ENV APP_ENV=production \
    APP_DEBUG=false

EXPOSE 8080

# --- ssr ----------------------------------------------------------------------
# The Inertia renderer: a Node process that turns a page object into HTML, so
# that somebody arriving from outside — or a crawler — gets a rendered page
# rather than an empty div. It is a separate image rather than an extra process
# in the production one on purpose: the container that answers requests has no
# Node in it, and this one has no PHP, no database credentials and no source.
FROM node:22-bookworm-slim AS ssr

WORKDIR /app

COPY --from=build --chown=node:node /app/bootstrap/ssr /app/bootstrap/ssr

USER node

# @inertiajs/core listens on 0.0.0.0:13714, which is what the app container
# dials through INERTIA_SSR_URL.
EXPOSE 13714

CMD ["node", "bootstrap/ssr/app.js"]

# --- development -------------------------------------------------------------
# Root on purpose: the source is a bind mount from the host, and a container
# user that does not match the host's would spend its life fighting the
# ownership of files it did not create.
FROM toolchain AS development

COPY docker/php-development.ini /usr/local/etc/php/conf.d/zzz-env.ini

ENV APP_ENV=local \
    APP_DEBUG=true \
    COMPOSER_ALLOW_SUPERUSER=1

EXPOSE 8080
