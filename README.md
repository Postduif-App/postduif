<p align="center">
  <img src="resources/images/readme-banner.svg" alt="Postduif" width="820">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3+-16160F?style=flat-square&labelColor=16160F&color=E8C81E" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-13-16160F?style=flat-square&labelColor=16160F&color=E8C81E" alt="Laravel 13">
  <img src="https://img.shields.io/badge/React-19-16160F?style=flat-square&labelColor=16160F&color=E8C81E" alt="React 19">
  <img src="https://img.shields.io/badge/Pest-5-16160F?style=flat-square&labelColor=16160F&color=E8C81E" alt="Pest 5">
  <img src="https://img.shields.io/badge/license-MIT-16160F?style=flat-square&labelColor=16160F&color=E8C81E" alt="MIT license">
</p>

<p align="center">
  Team messaging for workspaces — channels, threads, direct messages, tickets
  and presence, in a single self-hosted Laravel application.
</p>

<p align="center">
  <a href="#what-it-does">What it does</a> ·
  <a href="#stack">Stack</a> ·
  <a href="#getting-started">Getting started</a> ·
  <a href="#on-ploi">Deploying</a> ·
  <a href="#when-it-does-not-work">Troubleshooting</a> ·
  <a href="#architecture">Architecture</a> ·
  <a href="#testing">Testing</a>
</p>

---

Postduif is built around one idea: a workspace decides what it offers. Which
parts of the product are switched on, who may create a channel, who may post in
it, what a guest is allowed to see — all of it is a setting rather than a fork.

## What it does

**Messaging.** Public and private channels, direct messages, group DMs, threaded
replies, quoted replies, reactions, pins, bookmarks, message editing and
deletion, forwarding, and full-text search across everything you may see. Link
previews are fetched in the background; blocked words are censored on the way in.

**Presence and status.** Live presence per channel over WebSockets, a manual
availability status, and status rules that set it for you on a schedule ("away
after 18:00").

**Huddles.** Talking in a channel at the press of a button, with audio and
optionally video, without scheduling anything. Browsers connect to each other
directly; the offers and answers ride the presence channel the conversation is
already on. Needs `HUDDLE_STUN_URLS` — see **Environment notes**.

**Tickets.** A channel can double as a queue. Tickets carry a status, a
priority, a timeline of events, comments and attachments, and nag their channel
when they go stale.

**Reach outside the app.** Incoming webhooks post into a channel, scheduled
messages go out at a time you pick, invite links and per-person invitations let
people in, and Pushover pushes channel activity to a member's own phone.

**An MCP server.** `/mcp/chat` exposes the chat to AI clients through four
tools — find channels, search messages, send a message, set your status — behind
a personal token, running against exactly the same policies as the web app.

**An admin panel.** Filament v5 at `/admin`, covering workspaces, channels,
messages, attachments, tickets and users.

## Stack

| Layer | Choice |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13 |
| Frontend | Inertia 3 + React 19, TypeScript, Tailwind v4, Radix UI |
| Realtime | Laravel Reverb (WebSockets), Laravel Echo |
| Auth | Laravel Fortify, with passkeys |
| Feature flags | Laravel Pennant |
| Media | spatie/laravel-medialibrary |
| Admin | Filament v5 |
| Database | PostgreSQL |
| Cache / sessions | Redis |
| Containers | Docker, FrankenPHP |
| Tests | Pest 5 |
| Static analysis | Larastan, PHPStan |
| Formatting | Pint (PHP), Prettier + ESLint (TS) |

## Getting started

### With Docker

Requirements: Docker with Compose v2. Nothing else — no PHP, no Node, no
database on your machine.

```bash
git clone git@github.com:SebastiaanKloos/postduif.git postduif && cd postduif
docker compose up
```

That builds the image, starts PostgreSQL, Redis, the app, Reverb, a queue
listener, the scheduler and Vite, installs the dependencies, migrates and seeds.
The first run takes a few minutes; the ones after it take seconds.

Then open <http://localhost:8000> and sign in as `test@example.com` with the
password `password`. Three colleagues (`fenna@`, `joris@`, `amara@`) are seeded
along with a workspace that already has conversations in it, and the login page
offers one-click sign-in for all of them.

The source is bind-mounted, so editing a file changes what the container serves.
Artisan and the rest run inside:

```bash
docker compose exec app php artisan tinker
docker compose exec app php artisan test
docker compose exec app composer lint
```

| Service | Port | What it is |
| --- | --- | --- |
| app | 8000 | The application |
| vite | 5173 | The dev server for the frontend |
| reverb | 8080 | WebSockets |
| postgres | 5432 | Database |
| redis | 6379 | Cache and sessions |

Every one of those is overridable — see [Ports are taken](#ports-are-taken).

`docker compose down` stops it all; add `-v` to throw away the database and
start from a clean seed.

#### Production

A different arrangement of the same image: assets built in, no Vite, no bind
mount, and the worker and scheduler as processes of their own.

```bash
cp .env.example .env    # set APP_KEY, DB_PASSWORD and the REVERB_* values
docker compose -f compose.prod.yaml up -d --build
```

Two things worth knowing before you deploy it:

- **TLS is not in here.** Put a reverse proxy in front and give Reverb a route
  of its own — a browser on an https page will not open a plain `ws://` socket.
- **`REVERB_APP_KEY` and `REVERB_HOST` are baked into the frontend bundle**, so
  they are build arguments rather than runtime settings. Change one and rebuild
  with `--build`; the compose file reads both from your `.env`.
- **Pages are server-rendered by a second image.** Inertia's renderer needs
  Node, and the container that answers requests deliberately has none, so it
  runs as its own `ssr` service: a Node process with the page bundle in it and
  nothing else — no PHP, no source, no credentials, no published port. Stop it
  and the app keeps working; pages are then rendered in the browser instead.

Migrations run in a one-shot `migrate` service that everything else waits for,
so four containers starting at once do not all try to migrate. Uploads live in
the `storage` volume — attachments and transfers are on the private disk, and
without that volume a deployment would take them with it.

### On your own machine

Requirements: PHP 8.3 or newer, Composer, Node 22+, PostgreSQL, Redis.

```bash
git clone git@github.com:SebastiaanKloos/postduif.git postduif && cd postduif
composer setup      # install, .env, key, migrate, npm install, npm run build
```

Point `DB_*` in `.env` at your PostgreSQL database, fill in `REVERB_APP_ID`,
`REVERB_APP_KEY` and `REVERB_APP_SECRET`, then:

```bash
php artisan migrate
composer dev        # serves the app, queue, Reverb and Vite together
```

Alternatively, if you use [Solo](https://soloterm.com), `solo.yml` in the repo
root starts Reverb, the queue listener, Vite and `pail` in one screen — it
assumes the app itself is served by Valet at `https://postduif.test`.

Outside production, `routes/dev.php` adds a quick-login endpoint so you can hop
between seeded users without typing a password.

### On Ploi

[Ploi](https://ploi.io) provisions the server and you arrange the rest. What it
does not give you by default is the part that matters here: this application is
four long-running processes beside php-fpm, not one.

Create the server with **PHP 8.4**, **PostgreSQL** and **Redis** — MySQL is not
an option, `DB_CONNECTION` is `pgsql`. Then check the extensions, because the
image only ships the usual ones:

```bash
php8.4 -m | grep -E "pgsql|redis|gd|exif|intl|bcmath|pcntl|zip"
```

`gd` has to be built with webp (attachments get a webp preview, see
`Message::registerMediaConversions`), and `pcntl` is what the queue worker and
Reverb use to catch the signal that tells them to stop. Without it neither shuts
down cleanly, and a deploy leaves the old code running.

Create the site with web directory `/public`, install the repository, request a
certificate, and fill in the environment. Two settings differ from the example
file in a way that is easy to miss: `BROADCAST_CONNECTION` must be `reverb`
rather than `log`, and the `REVERB_*` values split into two pairs — the daemon
listens on `REVERB_SERVER_HOST=0.0.0.0` and `REVERB_SERVER_PORT=8080`, while the
browser is told `REVERB_HOST=chat.example.com`, `REVERB_PORT=443` and
`REVERB_SCHEME=https`. nginx sits between the two.

#### The deploy script

```bash
cd /home/ploi/chat.example.com

git pull origin main

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Wayfinder runs `php artisan` from inside the Vite build, so the autoloader and
# the package manifest have to exist before npm does anything. Not an
# optimisation: the frontend build fails without it.
php artisan package:discover --ansi
php artisan filament:upgrade
php artisan translations:types

npm ci --no-audit --no-fund
npm run build
npm run build:ssr

php artisan migrate --force
php artisan optimize
php artisan filament:optimize

php artisan queue:restart
php artisan reverb:restart
php artisan inertia:stop-ssr || true

echo "" | sudo -S service php8.4-fpm reload
```

`node_modules` stays where it is. `npm run build:ssr` uses the plain Vite config,
which leaves dependencies out of the bundle and imports them at runtime — only
the Docker variant (`vite.config.ssr.ts`) bundles them in.

#### Daemons and cron

Three daemons, all in the site directory as user `ploi`:

| Daemon | Command | Processes |
| --- | --- | --- |
| Queue | `php8.4 artisan queue:work --sleep=3 --tries=3 --max-time=3600` | 2 |
| Reverb | `php8.4 artisan reverb:start --host=0.0.0.0 --port=8080` | 1 |
| SSR | `node bootstrap/ssr/app.js` | 1 |

`--max-time` because a worker that never restarts holds code from before the
deploy in memory. The SSR renderer is the one you can lose without anybody
noticing much: pages then render in the browser instead.

And one cron, every minute:

```
cd /home/ploi/chat.example.com && php8.4 artisan schedule:run >> /dev/null 2>&1
```

Ten things stand still without it — see [Scheduled work](#scheduled-work).

#### Letting the websocket through

In the site's nginx configuration, above the existing `location /`:

```nginx
location ~ ^/(app|apps) {
    proxy_pass          http://127.0.0.1:8080;
    proxy_http_version  1.1;
    proxy_set_header    Host $host;
    proxy_set_header    X-Real-IP $remote_addr;
    proxy_set_header    X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header    X-Forwarded-Proto $scheme;
    proxy_set_header    Upgrade $http_upgrade;
    proxy_set_header    Connection "Upgrade";
    proxy_read_timeout  60s;
}
```

Both paths, not just the first: Reverb serves the client on `/app/{key}` and its
HTTP API on `/apps/{id}/events`, and that second one is what the application
itself calls to publish an event.

### On Ploi Cloud

[Ploi Cloud](https://ploi.cloud) is the other product — a managed platform where
the application is a container and everything beside it is a service you add
rather than a daemon you write. Nothing above applies; the shape of the
deployment does.

Point it at the repository, pick **PHP 8.4** and **Node 22** (the Node version is
a setting, and without it the frontend cannot be built), then add the services:

| Service | What it is |
| --- | --- |
| PostgreSQL | The database. Credentials are injected as secrets. |
| Redis | Sessions, cache and the queue. |
| Worker | `php artisan queue:work --sleep=3 --tries=3` |
| Laravel Reverb | A worker type of its own, on port 6001. |
| Inertia SSR | Custom service → Rendering → `php artisan inertia:start-ssr` |

The scheduler is a switch in the application's settings rather than a service —
turn it on, and `schedule:run` goes every minute.

PHP extensions come from `ploi.yaml` in the repository root, through
`php.extensions`. The same list the Dockerfile installs applies:

```yaml
php:
    extensions:
        - bcmath
        - exif
        - gd
        - intl
        - pcntl
        - pdo_pgsql
        - redis
        - zip
```

#### Build and init

Build commands run while the image is made, init commands run before every
start. The split matters more here than on a server, and in one direction:
`php artisan config:cache` belongs in **init**, never in the build. Cache the
configuration before the services exist and you bake a database password that is
an empty string.

```bash
# Build
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
php artisan package:discover --ansi && php artisan filament:upgrade
php artisan translations:types
npm ci --no-audit --no-fund
npm run build
npm run build:ssr

# Init
php artisan migrate --force
```

Ploi Cloud keeps one list of secrets and hands it to both the build and the
runtime, which is what makes `VITE_REVERB_APP_KEY` and `VITE_REVERB_HOST` work
at all: they are compiled into the bundle, so they have to be readable while
Vite runs. It also means a redeploy is not enough after changing either one —
the image has to be rebuilt.

#### Storage

The container filesystem is ephemeral and every instance has its own, so
attachments, transfers and secrets need a **persistent volume** on
`/storage/app`. Without one they survive until the next deploy and no longer.
Add it before the first upload: creating a volume replaces the contents of the
directory it mounts over.

`MEDIA_DISK` stays `local` — the files are served through a route that checks
whether you may see the channel, and the volume is shared across instances, so
scaling out does not break it. S3 is the alternative if the volume outgrows
being convenient, with the bucket kept private for the same reason.

#### Behind the load balancer

TLS ends at Ploi Cloud's load balancer and the request reaches the application
over plain HTTP with `X-Forwarded-Proto: https`. Nothing in `bootstrap/app.php`
trusts that header yet, because neither Docker nor Valet needs it, so this is a
change you make before the first deploy:

```php
->withMiddleware(function (Middleware $middleware): void {
    // Only the load balancer can reach the container, so there is no untrusted
    // hop for a client to forge these from.
    $middleware->trustProxies(at: '*');

    // ...
})
```

Without it every generated URL, redirect and mail link comes out as `http://` —
and a browser on an https page refuses to open the websocket those URLs point
at. On Ploi the same applies with `at: '127.0.0.1'`, since nginx is on the box.

### When it does not work

#### Ports are taken

The likeliest reason `docker compose up` refuses to start, and the one nobody
can guess: something on your machine is already listening. A local PostgreSQL on
5432 and a Homebrew Redis on 6379 are the usual suspects, and if you also run
this app outside Docker, Reverb is already on 8080.

Find out what is holding a port with `lsof -nP -iTCP:5432 -sTCP:LISTEN`, then
either stop it or move the container's side of it — every published port is a
variable, and compose reads them from your `.env` as well as your shell:

```bash
APP_PORT=8001 REVERB_PORT=8081 FORWARD_DB_PORT=55432 FORWARD_REDIS_PORT=56379 \
  docker compose up
```

`VITE_PORT` moves the dev server. Only the app's own port needs to end up in
`APP_URL` — the rest are told to the browser by the compose file.

#### Reverb does not connect

The websocket is where the chat lives, so this one is visible: messages arrive
only after a reload, and the browser console says the connection to
`ws://localhost:8080/app/...` failed.

- **Is it running?** `docker compose ps reverb` should say healthy, and
  `docker compose logs reverb` should end in `Starting server on 0.0.0.0:8080`.
  If it says *secure* server instead, it is expecting TLS: `REVERB_TLS_CERT`
  names a certificate. The compose file blanks that variable, because a path to
  a certificate on your own machine means nothing inside a container.
- **Is the browser looking in the right place?** The bundle is told
  `VITE_REVERB_HOST=localhost` and the published Reverb port. If you moved that
  port with `REVERB_PORT`, restart the `vite` service so it picks the new one
  up; in production the value is compiled in, so change it and rebuild.
- **Nothing arrives, but the socket is open.** Then it is not Reverb. Check
  `docker compose logs queue` — broadcasts are queued, so a stopped worker looks
  exactly like a broken websocket.

#### The page loads without styling

Vite is not running, or it stopped without cleaning up after itself. Check
`docker compose logs vite`, and if `public/hot` still exists while the container
is down, delete it: it points the app at a dev server that is no longer there.

#### `npm ci` says the lock file is out of sync

Somebody added a dependency without updating `package-lock.json`. That is
deliberate: the container will not silently rewrite a lock file in your working
copy with a different npm than yours. Run `npm install --package-lock-only` on
your own machine and commit the result.

### Environment notes

- `MEDIA_DISK` defaults to `local` **on purpose**. Attachments are served
  through a route that asks whether you may see the channel; a public disk would
  put a screenshot from a private channel one forwarded link away.
- `PUSHOVER_TOKEN` switches phone pushes on for the whole install. Members set
  their own device key in their notification settings.
- Serving over HTTPS means Reverb needs TLS as well — point `REVERB_TLS_CERT`
  and `REVERB_TLS_KEY` at your Valet certificate.
- `HUDDLE_STUN_URLS` is what makes huddles work at all. Empty leaves the feature
  switched off rather than half working — see below.

#### Huddles need somewhere to look

A browser in a huddle talks to the other browsers directly, so both sides have
to know where to send the audio. On the same network they work that out
themselves. Anywhere else they cannot: what a browser sees as its own address is
a number on the inside of a router, and telling that to somebody on the other
end of the internet is useless.

- **STUN** (`HUDDLE_STUN_URLS`) is a server that answers one question: *what
  does my address look like from where you are standing?* That is enough for
  most home connections. It is also the floor — without it the only huddle that
  connects is one between two people on the same wifi, so the button is hidden
  entirely rather than offered and left to fail. Comma-separate several;
  `stun:stun.l.google.com:19302` is the usual free one to start with.
- **TURN** (`HUDDLE_TURN_URLS`) is a relay that carries the audio when there is
  no direct path to find at all — symmetric NAT, which is what a good many
  company networks and mobile providers do. Optional, but without it a share of
  your colleagues will watch "Connecting…" and never get further.
- **`HUDDLE_TURN_SECRET`** is coturn's shared secret (`static-auth-secret` in
  its config). Rather than every member getting a fixed relay login — a login
  anybody who opens the page can use for anything, indefinitely — the
  application signs a username that expires, currently after
  `HUDDLE_TURN_TTL_MINUTES` (120). The secret itself never leaves the server.
  Leave it empty if your relay wants a plain username and password.

Both lists are read per request in `App\Actions\Huddles\IceServers` rather than
compiled into the frontend, so moving to another relay is an env change and a
restart, not a rebuild. Only somebody who may actually join gets them — a TURN
credential is a working relay for as long as it lasts.

## Architecture

```
app/
├── Actions/        The domain layer, grouped per context
│   ├── Chat/       SendMessage, EditMessage, ToggleReaction, SearchMessages, …
│   ├── Tickets/    CreateTicket, UpdateTicket, RecordTicketEvent, …
│   ├── Users/      SetStatus, ApplyStatusRules, GenerateHandle, StoreAvatar
│   └── Workspace/  Invitations, invite links, guest access, theming
├── Features/       Per-workspace feature flags (Pennant)
├── Policies/       Authorisation — the single source of "may I see this?"
├── Mcp/            MCP server and its four tools
├── Filament/       Admin panel resources
├── Events/         Broadcast events (MessageSent, StatusChanged, …)
└── Http/           Thin controllers, one concern each
```

**Actions carry the logic.** Controllers validate, authorise and delegate.
When behaviour is shared between the web app, the MCP server and a webhook, it
lives in an action, and all three call it — which is why an AI client cannot
sidestep a policy the browser is held to.

**Policies are the one place visibility is decided.** `ChannelPolicy::view`
answers for the channel list, for a single message, for an attachment download
and for MCP search alike.

**Feature flags are classes, not closures.** `app/Features/*` are Pennant
features that also carry a human-readable name and a sentence about what
switching them off costs, so the admin screen can present them honestly. Seven
exist today: scheduled messages, saved messages, forwarding, tickets, webhooks,
invite links and AI access.

**Roles.** `Owner`, `Admin`, `Member` and `Guest`. A guest belongs to the
workspace only as far as the channels they were invited to: they cannot browse
public channels or see who else is in the workspace. Every visibility check asks
`canBrowseWorkspace()` rather than comparing against the case, so a future
restricted role inherits the same treatment.

### Scheduled work

| Command | Interval | Why |
| --- | --- | --- |
| `chat:dispatch-scheduled` | every minute | 09:00 means 09:00 |
| `users:apply-status-rules` | every minute | same reasoning |
| `chat:notify-absent` | every 15 min | shortest threshold on offer is 30 min |
| `tickets:notify-stale` | hourly | thresholds are measured in hours |

## Testing

```bash
php artisan test --compact                    # all tests
php artisan test --compact --filter=Ticket    # one slice
composer test                                 # lint + phpstan + tests
composer ci:check                             # everything CI runs
```

Tests are Pest 5 and run against a real PostgreSQL database (`postduif_testing`,
see `phpunit.xml`). Coverage is feature-first: over a hundred feature test files
exercise the HTTP surface, and unit tests are reserved for logic worth isolating.

Two suites at once — a second person, or an agent working alongside you — will
deadlock on that one database: both truncate the same tables between tests, and
Postgres reports `SQLSTATE[40P01]: Deadlock detected` in whichever run lost.
Give the second one its own:

```bash
createdb postduif_testing_b
POSTDUIF_TEST_DB=postduif_testing_b php artisan test --compact
```

`tests/bootstrap.php` picks that variable up after `phpunit.xml` has had its
say, which is the only point at which it can. The database has to exist —
nothing here creates it.

## Code style

```bash
vendor/bin/pint --dirty     # PHP
npm run lint                # ESLint
npm run format              # Prettier
npm run types:check         # tsc
```

CI runs all four plus PHPStan on every push and pull request.

## Issue tracking

Issues live in [beads](https://github.com/gastownhall/beads) rather than in
GitHub Issues — a local Dolt database under `.beads/`, synced over
`refs/dolt/data` on the git remote. `bd ready` shows what is available to pick
up; `bd prime` explains the rest. `.beads/issues.jsonl` is a passive export, not
the source of truth.

## License

MIT.
