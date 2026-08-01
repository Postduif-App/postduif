# Postduif

Team messaging for workspaces — channels, threads, direct messages, tickets and
presence, in a single self-hosted Laravel application.

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
| Tests | Pest 5 |
| Static analysis | Larastan, PHPStan |
| Formatting | Pint (PHP), Prettier + ESLint (TS) |

## Getting started

Requirements: PHP 8.3 or newer, Composer, Node 22+, PostgreSQL, Redis.

```bash
git clone git@github.com:SebastiaanKloos/postduif.git pcom && cd pcom
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
assumes the app itself is served by Valet at `https://pcom.test`.

Outside production, `routes/dev.php` adds a quick-login endpoint so you can hop
between seeded users without typing a password.

### Environment notes

- `MEDIA_DISK` defaults to `local` **on purpose**. Attachments are served
  through a route that asks whether you may see the channel; a public disk would
  put a screenshot from a private channel one forwarded link away.
- `PUSHOVER_TOKEN` switches phone pushes on for the whole install. Members set
  their own device key in their notification settings.
- Serving over HTTPS means Reverb needs TLS as well — point `REVERB_TLS_CERT`
  and `REVERB_TLS_KEY` at your Valet certificate.

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

Tests are Pest 5 and run against a real PostgreSQL database (`pcom_testing`,
see `phpunit.xml`). Coverage is feature-first: over a hundred feature test files
exercise the HTTP surface, and unit tests are reserved for logic worth isolating.

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
