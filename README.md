# Renovo

A self-hosted tracker for subscriptions and recurring bills. Multi-user,
permission-scoped, with multi-currency totals, budgets, a twelve-month forecast,
price history, free-trial tracking, shared-cost splitting, upcoming-renewal
windows, notice periods, a versioned JSON API, CSV/JSON import, whole-household
backups, a calendar feed and a calendar view, attached invoices, saved views,
keyboard shortcuts, and a light/dark interface in whichever language you have a
catalogue for.

Built in phases:

- **Phase 1 — foundation, auth, roles and core tracking — complete.** Slim 4 on
  PostgreSQL or MySQL, argon2id sign-in, household roles, SHARED/ISOLATED data
  isolation applied centrally, and subscription tracking with billing-cycle
  normalisation.
- **Phase 2 — money — complete.** Exchange rates behind a pluggable provider,
  append-only price history with scheduled changes, free trials that convert,
  budgets that trigger on projected spend, a twelve-month forecast, per-period
  and year-over-year figures, a usage signal, a cancel-by dashboard,
  shared-cost splitting and bulk actions. See [Money features](#money-features).
- **Phase 3 — notifications and the scheduler — complete.** Reminders before a
  renewal, a trial conversion or a cancellation deadline, and an alert when a
  budget is projected to be exceeded; email, Gotify, Slack and generic webhook
  channels; per-user lead times, routing and digests; a daily scheduler that
  dispatches each alert exactly once; and an SSRF-hardened HTTP client with an
  administrator's trusted-host allowlist. See
  [Notifications](#notifications).
- **Phase 4 — advanced auth and the security surface — complete.** TOTP
  two-factor, passkeys and security keys (both as a way to sign in and as a
  second factor), an audit log an administrator or household Owner can read, and
  a list of active sessions you can revoke one at a time or all at once. See
  [Signing in](#signing-in).
- **Phase 5 — API, interoperability and files — complete.** A versioned
  `/api/v1` with full subscription CRUD including edit, authenticated by tokens
  you issue and revoke yourself; an OpenAPI 3 document that is the contract
  rather than a description of it, checked against the routes in CI; a CSV/JSON
  importer that maps columns and previews every row before writing any of them;
  whole-household backup and restore, files included, without touching the
  database; a read-only iCalendar feed of renewals, trial conversions and
  cancellation deadlines; and invoices or receipts attached to a subscription,
  stored outside the web root. See [The API](#the-api),
  [Importing](#importing), [Backup and restore](#backup-and-restore),
  [Calendar feed](#calendar-feed) and
  [Invoices and receipts](#invoices-and-receipts).
- **Phase 6 — language, the calendar, polish and operations — complete.** Every
  string the application shows now comes from a catalogue, so adding a language
  is adding one file and running one check; a month view of what is coming; list
  filters you can name and come back to; comfortable or compact lists, a
  dashboard you can rearrange, a quick-add dialog and keyboard shortcuts; icons
  fetched from a subscription's own website through the guarded HTTP client and
  cached per domain; liveness and readiness endpoints and a Prometheus
  `/metrics`; and a read-only demonstration mode. See
  [Language](#language), [Calendar](#calendar),
  [Making it yours](#making-it-yours),
  [Health and metrics](#health-and-metrics) and
  [Demonstration mode](#demonstration-mode).
- **Phase 7 — the build pipeline and offline assets — complete.** Tailwind
  compiled, a small JavaScript bundle (Chart.js lazily, tree-shaken Lucide
  icons) and the Inter webfont vendored from npm rather than linked from
  Google — all of it hashed into `public/build` with a manifest a Twig helper
  resolves, built into the image so the running container fetches nothing, and
  held to the rule that **nothing the browser loads comes from a third-party
  host** by a check CI runs over the build's output as well as its sources. It
  adds no feature; it is what a re-skin gets compiled by. See
  [Building the front end](#building-the-front-end).

- **Phase 8 — the design system — complete.** The colour, type and spacing the
  interface is built from, named once as Tailwind theme tokens the build
  compiles: a brand gradient sampled from the logo, a separate warm hue for
  urgency so "this is the action" and "this needs attention" stop competing,
  Inter applied with tabular figures so columns of money line up, and both the
  light and dark token sets. The stylesheet that dresses the application moved
  into the build and Preflight came on with it. It adds no feature and no
  screen; it is the vocabulary the re-skin in Phases 9–14 is written in. See
  [The design system](#the-design-system).

- **Phase 9 — the application shell — complete.** One frame every screen sits
  in: a fixed rail carrying the mark and the navigation, a top bar with the
  page's name and the one action worth offering, and a bottom tab bar with a
  no-JavaScript drawer below 768px. Every destination is declared once in a
  service, the highlighted item is worked out on the server from the request
  path, and the keyboard shortcuts still reach the same places. It adds no
  feature and moves nothing about permissions, money or scope. See
  [The application shell](#the-application-shell).

- **Phase 11 — my subscriptions — complete.** The subscriptions screen in the
  design's arrangement: a stats strip across the top, the list itself, the two
  deadlines the application distinguishes — a charge inside the near window, and
  the last day to give notice where a notice period exists — a section for
  trials before they convert, and the category distribution as proportion bars
  alongside. The list is the same list, so saved views, density, scope and
  permissions are unchanged by the restyle; the figures are the ones the
  Statistics page and the cancel-by view already produce. It adds no data and no
  new query path. See [My subscriptions](#my-subscriptions).

That is the v1 feature set, Phase 7 the toolchain under it and Phase 8 the
design language on top. Deliberately not in it: OIDC/SSO, and bank or
transaction sync — see the end of `PHASE.md` for what was deferred and why.

See `PHASE.md` for what was in scope for the last phase and `SPEC.md` for the
conventions every phase followed.

---

## Requirements

- **Docker** and the Compose plugin (the supported way to run it), or
- **PHP 8.2+** with `pdo_pgsql` or `pdo_mysql`, `intl`, `curl`, `zip`, `openssl`
  and `sodium` (the last two are bundled with most builds; they encrypt stored
  two-factor secrets and verify passkeys), plus Composer and a
  **PostgreSQL 14+** or **MySQL 8 / MariaDB 10.6+** server — and **Node 20+**
  to compile the front-end assets, which the published image and the Compose
  stack do for you.

SQLite is not supported. Development and production run the same engine, so a
query that works locally works in production.

Node is a *build* dependency and nothing more: the running application never
uses it, and a deployment from the published image never installs it. See
[Building the front end](#building-the-front-end).

---

## Quick start

```bash
./bin/dev-setup.sh --with-sample-data
```

That is the whole thing. It checks your prerequisites, writes `.env` with a
real session key, starts the stack, migrates both the development and test
databases, confirms the app actually serves a page, and — with that flag —
creates an administrator and a spread of sample subscriptions so there is
something on the dashboard to look at. It prints the sign-in details at the
end. Re-running it is safe; nothing already set up is overwritten.

The same script stops it again, so starting and stopping local development is
one command either way:

```bash
./bin/dev-setup.sh --stop              # stop everything; all data is kept
./bin/dev-setup.sh --stop --reset      # stop it and delete the data too
```

```bash
./bin/dev-setup.sh                     # no sample data; you run the wizard
./bin/dev-setup.sh --mysql             # MySQL/MariaDB instead of PostgreSQL
./bin/dev-setup.sh --reset             # start again from an empty database
APP_PORT=9090 ./bin/dev-setup.sh       # if 8080 is taken
./bin/dev-setup.sh --help              # all of the above
```

### Or by hand

```bash
cp .env.example .env
# Generate the session key — an instance will refuse to start without one.
printf 'SESSION_KEY=%s\n' "$(openssl rand -hex 32)" >> .env

docker compose up
```

Then open **http://localhost:8080** and complete the first-run wizard. The
account you create there is the instance administrator.

The `migrate` container applies the schema and then exits — seeing it as
`Exited (0)` in `docker compose ps` is correct, not a failure.

`docker compose up` starts six containers:

| Container   | What it does                                                       |
|-------------|--------------------------------------------------------------------|
| `web`       | nginx, serving the web root on `${APP_PORT:-8080}`                 |
| `app`       | php-fpm running the application                                    |
| `database`  | PostgreSQL 16, also published on `127.0.0.1:5432` for host tooling |
| `migrate`   | Applies migrations once, then exits                                |
| `scheduler` | Runs the daily console commands — see [Commands](#commands)        |
| `mailpit`   | Catches outbound mail in development — http://localhost:8025       |

### The two stacks

`docker compose up` runs the application **as it ships**: each container serves
what its own image contains, including the compiled assets, and nothing reaches
the network for an asset or a package. That is what an operator wants, and it
is what works on a host with no route to the npm registry.

For development you want the working tree instead — edit a stylesheet and see
it, without rebuilding an image. That is a second file:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up
```

which mounts `public/` into both `app` and `web` and adds one more container:

| Container | What it does                                                    |
|-----------|-----------------------------------------------------------------|
| `assets`  | Compiles the front-end assets and rebuilds them on change       |

`./bin/dev-setup.sh` uses both files, so you get this stack by default when you
use the script.

The `assets` container is why Node is not something you have to install: it
installs the JS dependencies into a volume, builds once, and then watches. The
first build takes a minute or so; until it finishes, a page will report a
missing asset manifest, because the mounted `public/` is shadowing the copy the
image built. `./bin/dev-setup.sh` waits for it before telling you the app is
ready.

Both containers that read the web root are given the same one, always — either
both from their images or both from the host. Letting them disagree is how a
stale stylesheet gets served against markup that postdates it.

### Running against MySQL / MariaDB

```bash
# Also set DB_DRIVER=mysql and DB_PORT=3306 in .env.
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up

# In development, with the working tree on top:
docker compose -f docker-compose.yml -f docker-compose.dev.yml \
               -f docker-compose.mysql.yml up

# Or just: ./bin/dev-setup.sh --mysql
```

---

## Running without Docker

```bash
composer install
npm install && npm run build  # compile the front-end assets into public/build
cp .env.example .env          # then edit DB_* and set SESSION_KEY

vendor/bin/phinx migrate      # create the schema
php -S localhost:8080 -t public
```

The asset build is not optional: every page resolves its stylesheet through the
manifest that build writes, so without it you get a clear error naming the
command above rather than an unstyled page.

Point a real web server at `public/` in production — nothing outside that
directory should be reachable over HTTP. `assets/`, `node_modules/` and
`vite.config.js` deliberately sit outside it; only `public/build/` is served.

PHP's built-in server does not serve `public/build/` with cache headers. That
costs nothing — the filenames are content-hashed, so there is nothing stale to
serve — but a real deployment should copy the `location /build/` block from
`docker/nginx/default.conf`.

---

## Upgrading

**Back up your database first.** Then, from the project directory:

```bash
docker compose pull && docker compose up -d --build
```

The image builds its own assets, so an upgrade brings new hashed filenames with
it and a returning browser fetches them rather than serving the previous
version from cache. On a bare-metal install, run `npm ci && npm run build`
after pulling.

Migrations are applied automatically by the `migrate` container on start. To do
it by hand:

```bash
vendor/bin/phinx migrate     # apply
vendor/bin/phinx status      # show which migrations have been applied
vendor/bin/phinx rollback    # undo the last one
```

Every migration ships a working `down()`. PostgreSQL has transactional DDL, so a
failed migration rolls back completely; MySQL does not, so migrations are kept
small and the applied version is tracked in `phinxlog` — `phinx status` will
show a partially applied set.

---

## Configuration

All configuration is environment variables; see `.env.example` for the annotated
list. `.env` is never committed. The ones that matter most:

| Variable                    | Notes                                                         |
|-----------------------------|---------------------------------------------------------------|
| `SESSION_KEY`               | Required. `openssl rand -hex 32`. Rotating it signs everybody out — and, unless `TOTP_ENCRYPTION_KEY` is set, makes every enrolled authenticator app unreadable (recovery codes still work). |
| `DB_DRIVER`                 | `pgsql` or `mysql`.                                           |
| `DB_HOST`                   | Host-side value for CLI tools; containers always use `database`. |
| `APP_URL`                   | Used to build links in emails — set it to the real URL.       |
| `SESSION_COOKIE_SECURE`     | Leave `true` unless you are serving plain HTTP on a trusted network. |
| `AUTH_MAX_ATTEMPTS_PER_*`   | Login, reset and second-factor throttling, per account and per IP. |
| `TOTP_ENCRYPTION_KEY`       | Optional. Encrypts stored two-factor secrets; falls back to `SESSION_KEY`. |
| `AUDIT_LOG_RETENTION_DAYS`  | How long audit entries are kept. 365 by default.              |
| `EXCHANGE_RATE_API_KEY`     | Only for providers that need one. Takes precedence over a key entered in the UI. |
| `EXCHANGE_RATE_TTL_SECONDS` | How long a cached rate table stays current. 12 hours by default. |
| `APP_LOCALE`                | The language and formatting an account gets before choosing its own. Any ICU locale with a catalogue in `translations/`. |
| `METRICS_TOKEN`             | Unset means `/metrics` does not exist. Set it to expose Prometheus metrics to a scrape job carrying the same bearer token. |
| `LOGO_CACHE_DIRECTORY`      | Where fetched site icons are cached, one file per domain. Under `var/` by default. |

---

## Commands

```bash
# Quality gates — all three must pass before a change is done
vendor/bin/phpunit           # tests
vendor/bin/phpcs             # PSR-12
vendor/bin/phpstan analyse   # static analysis, level 6

composer check               # all three in one go

# Front-end assets
npm install                  # or `npm ci` for an exact install from the lockfile
npm run build                # compile into public/build/
npm run watch                # rebuild on change

# Database
vendor/bin/phinx migrate
vendor/bin/phinx rollback
vendor/bin/phinx seed:run    # a few starter categories (run after setup)

# Console
php bin/console list
php bin/console reminders:run       # send due reminders and budget alerts
php bin/console rates:refresh       # fetch and cache exchange rates
php bin/console maintenance:prune   # expired sessions, tokens, throttle, notification and audit
                                    # records, plus abandoned import uploads
php bin/console i18n:check          # every locale against the base catalogue; non-zero on drift
php bin/console assets:offline-check # non-zero if anything the browser loads names a third-party host
php bin/console demo:seed           # the demonstration household, for read-only demo mode
```

The `scheduler` container runs `reminders:run` and `maintenance:prune` once a
day. It does **not** run `rates:refresh` — rates are refreshed lazily on page
views and cached for 12 hours, so an instance nobody visits will serve stale
rates. Add it to the schedule if that matters to you.

`reminders:run` is **safe to run as often as you like, and safe to run twice at
once**. Every message is claimed in the notification ledger before it is sent,
so a second run finds the work already done rather than doing it again. It
exits non-zero if some users could not be processed, while still processing the
rest.

### Running the scheduler from cron instead

If you would rather not run the `scheduler` container — on a host that already
has cron, say — disable it and add an entry of your own. Once a day, early, is
the intended cadence:

```cron
# m  h  dom mon dow  command
  15 7  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console reminders:run
  45 7  *   *   *    docker compose -f /srv/renovo/docker-compose.yml run --rm app php bin/console maintenance:prune
```

Or, on a bare-metal install:

```cron
  15 7  *   *   *    cd /srv/renovo && php bin/console reminders:run
  45 7  *   *   *    cd /srv/renovo && php bin/console maintenance:prune
```

Whatever runs it needs the same environment the application has — in
particular `DB_*`, `APP_URL` (notifications contain links, and a link needs to
know its own host) and the `SMTP_*` settings, since the scheduler is what
actually sends the mail.

### Tests

The unit suite needs nothing:

```bash
vendor/bin/phpunit --testsuite unit
```

The functional suite renders real pages, so it needs the assets built once —
`npm install && npm run build`, or just let `./bin/dev-setup.sh` do it. CI
builds them before running the suite.

The integration and functional suites need a real database, because the
behaviour they check — scoping, isolation, permissions — is enforced in SQL.

**These tests empty every table before each test.** Give them their own
database rather than your development one:

```bash
docker compose up -d database
docker compose exec database psql -U renovo -c "CREATE DATABASE renovo_test OWNER renovo;"

DB_NAME=renovo_test vendor/bin/phinx migrate
DB_NAME=renovo_test vendor/bin/phpunit
```

As a guard against the obvious accident, the suite refuses to run — skipping
with an explanation — against a database whose name does not contain `test`,
unless `CI` or `ALLOW_DESTRUCTIVE_TESTS=1` is set. It also skips, rather than
failing silently, when no database is reachable at all; in CI it fails instead.

CI runs the whole suite against **both** PostgreSQL and MySQL.

---

## How it fits together

```
public/       Web root. index.php and static assets, nothing else.
src/
  Controller/ Thin. Translate HTTP to a service call and back.
  Controller/Api/  The same, for JSON. Calls the identical services.
  Application/Api/ The API's representation, its JSON input translation,
              and the OpenAPI document reader.
  Service/    All business logic. Web controllers and API endpoints share it.
  Service/Import/  Parsing a CSV or JSON upload and mapping its columns.
  Repository/ All persistence. The only place SQL is written.
  Security/   Scope, permissions, password hashing, CSRF, sessions.
  Persistence/ PDO wrapper and the PostgreSQL/MySQL differences.
  Service/ExchangeRate/  Rate providers behind one interface.
  Service/Notification/  Scanning for alerts, dispatching them once.
  Notification/ The Notifier interface, the registry, and one class per channel.
  Http/       The shared outbound HTTP client, and the SSRF guard for
              user-supplied URLs.
  I18n/       The translator, the catalogue loader and the completeness check.
  Domain/     Money, cycles, roles, entities. No I/O.
templates/    Twig. No logic.
translations/ One flat catalogue per locale. Adding a language is adding a
              file here; see Language.
config/       Settings, container, middleware, routes.
migrations/   Phinx migrations and seeds.
openapi/      The API contract. Hand-written, served as-is, checked against
              the route table in CI.
var/          Not web-accessible. Caches, logs, attached invoices, cached site
              icons, and in-progress imports. Back this up alongside the
              database.
```

Four rules hold the design together:

1. **Money is always an integer number of minor units.** No float touches a
   currency value. Currencies with zero or three decimal places (JPY, KWD) are
   handled properly rather than assumed to have two.
2. **All persistence goes through a repository**, and household data goes
   through `AbstractScopedRepository`, which welds the scope predicate into
   every statement it emits — reads *and* writes. A route cannot bypass
   isolation by forgetting a `WHERE` clause, because it never writes one.
3. **Permissions are enforced server-side on every route.** Templates hide
   controls a user cannot use, but that is cosmetic; the 403 comes from
   middleware.
4. **All outbound HTTP goes through one client**, so a limit added there applies
   everywhere — and a URL that came from a *user* additionally goes through the
   guard, which is a different type the plain client cannot satisfy, so the two
   cannot be confused for one another by accident.

### Roles and data isolation

- **Instance admin** (`is_instance_admin`) manages users and global settings.
  It grants **no** access to household data — an admin who is not a member of a
  household sees nothing of it, by construction.
- **Household roles**, per membership: **Owner/Admin** (everything, including
  household settings), **Editor** (create, edit and delete subscriptions),
  **Viewer** (read only — any mutating endpoint returns 403).
- **Isolation mode**, instance-wide: **SHARED** (default; everyone in a
  household sees its subscriptions) or **ISOLATED** (each member sees only the
  ones they own). Every subscription stores both `household_id` and
  `owner_user_id`, and the mode is applied centrally.

### How costs are normalised

Monthly and yearly figures are integer arithmetic throughout.

- Monthly × 12, quarterly × 4, yearly × 1 — exact.
- Weekly and custom-N-day cycles use a **365.25-day year**: the annual cost is
  `round(price × 365.25 ÷ N)`. A 7-day custom cycle therefore matches the weekly
  cycle exactly, and a 28-day cycle is deliberately *not* the same as monthly.
- The monthly figure is always derived from the annual one, so the two columns
  of a report always agree.
- Division rounds half away from zero.
- **One-off and lifetime entries are excluded** from recurring totals and shown
  separately. Amortising a lifetime licence over an arbitrary horizon would make
  the headline figures mean something other than what they say.
- **Per-currency subtotals are always shown.** A combined total is offered
  alongside them when every currency involved can be converted; when one cannot,
  the combined figure is withheld and the missing currency is named. A total
  that silently omits a currency is a wrong number, not an approximate one.

Renewal dates clamp at month end: 31 January plus one month is 28 or 29
February, and the original day of the month is restored as soon as a month is
long enough, so a subscription billed on the 31st does not drift to the 28th.

---

## Building the front end

Everything the browser loads is compiled here and served from your own
instance. **Nothing comes from a CDN** — not the font, not the chart library,
not a stylesheet. That is not a preference: an instance on a LAN or behind
Tailscale may have no route to `fonts.googleapis.com` at all, and a page that
quietly depends on one is a page that breaks in exactly the deployment this
application is built for.

The distinction that makes it workable is **build time versus run time**.
Installing packages from npm to *produce* the bundle is fine, the same way
Composer pulls PHP packages. What is forbidden is the *running* application
reaching out, so the font, the chart library and the icons are compiled into
local files once and served locally forever after.

```bash
npm install        # or `npm ci` for an exact install from the lockfile
npm run build      # compile into public/build/
npm run watch      # rebuild on change
```

### What it produces

| Source                | Becomes                          | Loaded as                    |
|-----------------------|----------------------------------|------------------------------|
| `assets/css/*.css`    | `public/build/app-<hash>.css`    | `{{ bundle('app.css') }}`    |
| `assets/js/app.js`    | `public/build/app-<hash>.js`     | `{{ bundle('app.js') }}`     |
| Chart.js              | `public/build/chart-<hash>.js`   | fetched on first chart only  |
| Inter (Fontsource)    | `public/build/inter-*.woff2`     | `@font-face` in the built CSS |

Every filename contains a hash of the file's own contents, and
`public/build/manifest.json` maps a logical name to the current one. A template
asks for `app.css` and gets `app-C84PQZax.css`, so an upgrade changes the URL
and a returning browser fetches the new file instead of serving last week's from
a seven-day cache. nginx serves `/build/` with `immutable` and a one-year
lifetime precisely because the URL can be trusted to change.

Two helpers, because there are two kinds of file:

- **`bundle('app.css')`** — anything the build produces. Resolved through the
  manifest.
- **`asset('/assets/htmx.min.js')`** — anything served as written: the vendored
  htmx, the keyboard-shortcut script, the passkey script. Cache-busted with the
  file's modification time.

There used to be a second `<link>` on every page for a hand-written
`public/assets/app.css`. Phase 8 moved it into the build, because the design
tokens are Tailwind theme values and a utility in a template can only be
guaranteed to agree with a rule in a stylesheet if one compilation produces
both — see [The design system](#the-design-system).

`public/build/` is generated, so it is not in git. The published image builds
its own copy; `.dockerignore` keeps a local build out of the build context so
what ships is always what the image compiled. That is also why the `web`
container is built rather than a bare nginx image: it has to carry the web root
the assets are in — see [The two stacks](#the-two-stacks).

### Tailwind

Tailwind 4 is compiled through Vite's first-party plugin, with **Preflight on**
since Phase 8. Preflight is Tailwind's reset: it strips the browser's default
heading sizes, list markers, link underlines and control borders on the
assumption that everything is dressed explicitly. Through Phase 7 that
assumption was false, because the application was dressed by a separate
stylesheet written against those defaults. `assets/css/base.css` is what makes
it true — it re-establishes, deliberately and by name, each default the
templates actually rely on.

Tailwind scans `templates/`, `assets/js/` and the Twig extension for class
names, listed explicitly rather than discovered, so it does not crawl `vendor/`
looking for utilities. One quirk of `npm run watch`: a class you *add* appears
immediately, but one you *delete* lingers in the compiled stylesheet until the
watcher restarts. A one-shot `npm run build` is always exact, which is what
production and CI use.

### The webfont

Inter comes from **Fontsource** — Google Fonts' families repackaged for
self-hosting and installed from npm. The build copies the `.woff2` files into
the web root and rewrites the `@font-face` rules to point at them, so "use a
Google font" and "load nothing from Google" are both true at once. The SIL Open
Font License is copied out of the package alongside it, as
`public/build/inter-OFL.txt`.

Phase 8 applies it. The deciding reason for Inter specifically is narrow: its
subset carries the `tnum` OpenType feature, so a column of currency lines up
digit under digit. See [The design system](#the-design-system).

### The JavaScript

The bundle is deliberately small. Keyboard shortcuts, the quick-add dialog and
passkey registration stay in `public/assets/`, hand-written and served
directly, along with htmx — none of them needed bundling, so none of them were
moved. What the bundle provides is the two things that did:

```js
// Chart.js, in a chunk of its own: fetched the first time this is called, and
// never by a page that does not call it.
await window.Renovo.chart(canvas, { type: 'line', data: … });

// Lucide icons, tree-shaken down to the handful listed in assets/js/icons.js.
element.append(window.Renovo.icon('calendar'));
```

### Keeping it honest

```bash
php bin/console assets:offline-check
```

This scans the templates, the build's sources **and the build's output** for an
absolute `http(s)` URL, and exits non-zero on any it finds. The output matters
most: our own files are easy to review, and the realistic failure is a
dependency that hardcodes a font CDN in the stylesheet it compiles. CI runs it
after every build, so the offline rule is enforced by the pipeline rather than
trusted to review.

Adding a front-end dependency therefore means installing it from npm — never
adding a `<script>` or `<link>` that points somewhere else, not even
temporarily. If a URL is an identifier rather than an address (`xmlns` on an
`<svg>`, say), add its host to `ExternalAssetScanner::ALLOWED_HOSTS`.

---

## The design system

The colour, type and spacing every screen is built from. Phase 7 decided how
assets are compiled and served; this is what they express.

### One stylesheet, one definition of each token

`assets/css/` compiles to the single stylesheet each page links:

| File             | Holds                                                        |
|------------------|--------------------------------------------------------------|
| `tokens.css`     | every colour, radius and type size, and both theme sets       |
| `base.css`       | element defaults, re-established after Preflight              |
| `components.css` | the shell, cards, tables, forms, buttons, badges, dialogs     |
| `screens.css`    | the calendar, budget meters, price timeline, density          |

Nothing downstream writes a colour literal. Tokens are declared twice: once as
semantic custom properties (`--surface`, `--text`, `--accent`), and once in an
`@theme inline` block that hands the same properties to Tailwind. That is what
makes `bg-surface` in a template and `.card { background: var(--surface) }` in
a stylesheet the same colour by construction rather than by agreement.

The `inline` keyword matters: without it Tailwind resolves a theme value at
build time and bakes it into the utility, freezing every utility to whichever
theme compiled first. Note also that every `@theme` key is spelled differently
from the property it points at — a key assigned its own name compiles to
`--x: var(--x)`, which is circular and resolves to nothing.

### The brand, and why there are two gradients

The logo is a **teal-green → blue** gradient mark, kept in the repository at
`assets/brand/renovo-logo.png`. `--brand-from` (`#0daa9c`) and `--brand-to`
(`#1069bb`) are the only place those stops are written down, so re-sampling the
logo is a two-line edit. They are the mark's real colours: Phase 9 projected
every pixel of it onto the 135° axis the gradient runs along and averaged the
first and last twentieth.

They are used where nothing sits on top of them — the brand mark, the active
nav item, a featured card's wash. **A filled button is a flat colour, not a
gradient**: `--accent`, the same action colour a link and a focus ring use,
with `--accent-text` as its ink. Both are assigned per theme, so the button
follows light and dark like everything else, and the ink has one contrast ratio
to clear rather than a range of them. White text on the real mark would be
2.9:1 at the teal stop, which is why a button was never painted with the logo's
own colours.

Brand and urgency are deliberately **different hues**. Teal-green is "this is
the action"; amber→orange is "money is about to move" — renewing soon, a
cancel-by deadline, a budget projected over; a muted red is a genuine problem.
A single-accent palette cannot say two of those at once.

### Dark is a theme, not the application

Three states, not two. An account picks system, light or dark under
Settings → Appearance, and an explicit choice beats the browser:

```css
:root { /* light */ }
@media (prefers-color-scheme: dark) {
    :root:not([data-theme='light']) { /* dark */ }
}
:root[data-theme='dark'] { /* dark */ }
```

The `:not([data-theme='light'])` guard is the whole trick. Without it, someone
who chose light on a machine set to dark gets repainted dark — breaking the
setting for exactly the person who bothered to change it. This is also why the
token layer uses no `dark:` variants: Tailwind's default `dark:` understands
only the media query, so it would disagree with the setting in that same case.

### Typography

Inter, self-hosted, with **tabular figures on every number**. That is the
deciding reason for the family: proportional digits are different widths, so a
column of amounts shifts as its values change. Switched on with
`font-variant-numeric: tabular-nums` rather than `font-feature-settings: "tnum"`,
which would switch off the other features in the font, and left off for running
prose.

| Role            | Size / weight             |
|-----------------|---------------------------|
| KPI value       | 2.5rem, 700, tabular      |
| Section heading | 1.25rem, 600              |
| Body / table    | 0.9375rem, 400–500        |
| Label / caption | 0.8125rem, 500, muted     |

### Contrast is tested, not eyeballed

`tests/Unit/DesignTokensTest.php` reads the compiled stylesheet and asserts
every text token clears WCAG AA (4.5:1) on the surface it is used against, in
both themes, and that control borders clear 3:1. The palette decides whether
the application is readable, so a token nudged darker to "look better" fails
the build rather than shipping.

### A note for operators

Changing how Renovo looks now needs `npm install && npm run build` rather than
an editor and a reload — the cost of having one compilation own both the
utilities and the rules. Most of what you would want to change is a handful of
custom properties at the top of `assets/css/tokens.css`.

## The application shell

Every signed-in page is rendered in one frame, so a screen is about its own
content and nothing else.

### What it is made of

- A fixed **240px rail** on the left: the mark, the primary destinations, and a
  tools group pinned to the bottom. Each item is an existing route with a
  tree-shaken Lucide icon.
- A **top bar**: the page's `<h1>`, whatever that page offers (edit this, back
  to the list), a search that goes to the subscriptions list, quick-add, and who
  is signed in.
- Below **768px** the rail becomes a **bottom tab bar** of the four most-used
  destinations with the rest behind a `<details>` drawer. It is a disclosure
  element rather than a scripted panel, so it opens with JavaScript off: a
  narrow screen loses the layout and none of the reach.

Two things from the design mock are deliberately absent. There is no **Upgrade
Plan** card — Renovo is self-hosted and there is no plan to sell — and no
**Manage Balance** pill, because the application tracks what is due rather than
a balance. Quick-add takes the prominent-action slot instead.

### One definition of the navigation

`src/Service/NavigationService.php` declares every destination once: its label
key, route, icon, the permission it needs, and the paths it claims. The rail,
the tab bar and the drawer are three projections of that one list, so a page
cannot be reachable on a desktop and missing on a phone — a test asserts the
two sets are equal.

**Which item is highlighted is decided on the server**, from the request path,
so the highlight is correct in the HTML that arrives rather than being fixed up
by script after the page has painted. The rule is most-specific-claim-wins,
which is what keeps `/settings/notifications` on Notifications rather than
lighting Settings as well.

Items a user may not use are not shown to them — a Viewer is offered no import
or audit link. That is a courtesy, not a control: `RequirePermissionMiddleware`
is what answers 403.

### The mark

`templates/partials/brand_sprite.twig` holds the supplied logo traced to a
single outline — under 2kB of path data — filled with the brand gradient
through the tokens, so it recolours with the theme instead of carrying the
wordmark's navy onto a near-black page. The wordmark itself is not recreated;
what sits beside the mark is the instance name, which is the operator's to set.

### Keyboard shortcuts

`?` lists them, `n` opens quick-add, `/` focuses search — the page's own search
where there is one, the top bar's otherwise — and `g` then a letter navigates
(`d`, `s`, `c`, `b`, `f`, `t`, `a`). Every destination is also a link somebody
can click, and a test reads the destinations out of the script to prove it.

## My subscriptions

The subscriptions screen carries the list and, around it, the four things a
person opens it to find out.

### The strip

Active count, yearly spend and renewals in the near window. The yearly figure
follows the rule the rest of the application follows — per-currency subtotals,
with a combined total only when every currency in play converts — through the
same partial the dashboard's tiles use, so the two screens cannot come to
different conclusions about the same money. A single clean number is the common
case, not the only one.

### The list is the list

It is the existing list fragment, restyled and rearranged rather than rebuilt,
which is why **saved views** still store the query the list itself produced,
**density** still tightens the same markup, and scope and permissions are
exactly what the repository and the middleware already enforced. Filtering,
sorting and paging still swap that fragment alone: the sections around it are
computed for a whole page and not for a keystroke.

### Two deadlines, not one

- **Renewing soon** — a charge falling inside the near window, which is the
  cancel-by view's fourteen days, referenced rather than re-chosen.
- **Cancel by** — the last day notice can be given, shown only for a
  subscription that *has* a notice period. Without one that deadline is the
  renewal date, and a second card repeating it would be noise. A deadline
  already missed is kept and marked: nothing can be done about it, but being
  committed to another period is worth knowing.

Each row offers Pause only to a member who could actually use it — their role
may change a subscription **and** the isolation mode leaves that row writable.
Under ISOLATED isolation a member can see a shared cost they contribute to
without being able to change it, so the button that would be refused is not
drawn. The refusal itself still lives in the repository.

### Free trials

Every trial that has not converted yet, not a window of them. **The last day of
a trial is the day of its first charge**, so the countdown runs to that day and
the amount shown is the price it converts to. A trial is the subscription it
will become, so pausing it here pauses the subscription.

### Category spending

The Statistics page's category breakdown as proportion bars. What each bar is a
share *of* is stated rather than assumed: the combined monthly total when every
currency converts, and otherwise one group per currency against that currency's
own total. Two currencies with no rate between them are never blended into one
bar, because comparing them is precisely the claim a bar makes.

## Money features

### Exchange rates

Rates are fetched through the shared HTTP client from a pluggable provider and
cached instance-wide.

| Provider           | Key needed | Notes                                          |
|--------------------|------------|------------------------------------------------|
| **Frankfurter**    | No         | **Default.** European Central Bank daily rates. |
| exchangerate.host  | Yes        | Wider currency list; free account required.    |
| Fixer              | Yes        | Never the default. Free tier is EUR-based; other bases are derived. |

The provider is chosen in the first-run wizard and can be changed in
**Settings → Instance**. A key may be entered there, but `EXCHANGE_RATE_API_KEY`
in the environment takes precedence — an operator who keeps the key out of the
database is not overridden by anything typed into the UI.

Rates are stored as integers scaled by 10^8, against the instance's base
currency; any other pair is cross-rated through it. Conversion is integer
arithmetic that refuses rather than overflowing.

**Everything degrades rather than guesses.** No rate for a currency means no
combined total for anything containing it — the per-currency figures are shown
instead, and the budget or statistic says which currency it could not convert.
A refresh is attempted lazily when the dashboard is viewed and the cache is
stale, at most once an hour, and any failure is logged and ignored. Run
`php bin/console rates:refresh` from the scheduler to keep rates current without
depending on somebody loading a page.

### Price history

Prices are **append-only**. A change is a new row with the date it takes effect;
nothing is ever overwritten, so what a subscription cost last year stays
knowable. The current price is the latest row whose date has arrived, and a
**scheduled** change is one whose date has not. Scheduled changes are counted in
forecasts and budgets from their own dates, and become the current price when
the day arrives.

### Trials

A trial is the subscription it will become, not a separate record. **The trial's
last day is the day the first charge falls** — a trial ending on the 30th is
free up to and including the 30th, and the conversion is dated the 30th even if
nobody opens the application for a fortnight afterwards. Conversion keeps the
subscription's identity, category, tags and history, and appears in the price
trend as the step it is.

### Budgets

A budget belongs to a member and measures **that member's own share** — their
subscriptions, plus their portion of anything split. The trigger is **projected**
spend, taken from the same forecast the Forecast page shows, so the two can
never disagree. Both periods are rolling windows from today ("the next month",
"the next 12 months") rather than calendar periods, because this application
tracks what is *due* rather than keeping a ledger of what has been *paid*, and a
calendar month would have to leave out whatever was charged earlier in it.

### Forecast

Twelve months, each renewal shown in the month it actually falls rather than
spread evenly. Scheduled price changes and trial conversions are applied from
their own dates, so a figure does not move when the change eventually happens.

### Shared costs

A cost can be split equally or by weight between household members. **Weights
are stored; amounts are derived** from the current price every time, so a price
rise divides the same way the original did. Shares always sum to the price
exactly — the odd penny goes to a specific member rather than disappearing.

A member listed on a split can **see** the subscription they help pay for even
when the instance is ISOLATED and they do not own it. They cannot change it,
delete it, re-tag it or alter the split: the scoping layer applies that widening
to reads only, and every write path uses the unwidened predicate.

### Year over year

Reconstructed from start dates, billing cycles and recorded price history —
there is no payment ledger. A subscription with no start date contributes
nothing to either year, which under-reports the past rather than inventing
spending that may never have happened. The page says how many were excluded.

---

## Notifications

Renovo tells you before money moves, not after. Four things are worth an
interruption and nothing else is:

| Alert | Fires when |
| --- | --- |
| **Upcoming renewal** | A payment is coming up, at each of your lead times. |
| **Trial about to convert** | A free trial is about to start charging, quoting what it will cost. |
| **Cancellation deadline** | The last day to give notice and avoid the next charge — only when a subscription has a notice period, since otherwise the deadline *is* the renewal date. |
| **Budget projected to be exceeded** | A budget's projection crosses its limit. |

Everything is configured per user under **Settings → Alerts**; each member of a
household sets their own. You are notified about the subscriptions you own and
the ones you pay for, not about everything in the household — a household of
four would otherwise quadruple everybody's notifications.

### Channels

**Email** (through the instance's SMTP relay), **Gotify**, **Slack** (a channel
or a direct message, via a bot token) and a **generic webhook** that posts a
documented JSON payload, optionally signed with HMAC-SHA256 in an
`X-Renovo-Signature` header.

Add as many as you like, name them, route each alert type to whichever you
want, and **send a test message** — a token that looks right and is not is
better discovered now than during the renewal you missed. A channel that fails
shows the reason next to it.

### When they arrive

The scheduler runs once a day. Lead times are a list — `30, 7, 1` means three
separate reminders — and a subscription can override the list with its own, or
opt out entirely by setting its reminders to `none`.

Nothing is ever sent twice. Each message is recorded before it goes out, keyed
to the charge date and the lead time, so running the scheduler repeatedly sends
nothing extra. The same record is what makes a *missed* run harmless: if the
scheduler is down on the seventh day before a charge and next runs on the
fifth, the seven-day reminder is still owed and is still sent.

Switch to a **weekly or monthly summary** if individual messages are too much.
A summary collects everything coming up into one message on the day you choose;
lead times stop applying, and each thing is mentioned once. (A summary whose
day is missed entirely — the scheduler down for that whole day — is skipped
rather than deferred.)

A budget alert fires on the crossing, not on the state: a budget that stays
over its limit for three weeks says so once, and only speaks again if it drops
back under and then goes over afresh.

### Where notifications may be sent

**Any URL you configure is fetched by the server, not by your browser**, which
means an unchecked URL would let anybody who can add a webhook reach whatever
the server can reach — your router, a NAS, or on a cloud host the metadata
service holding the machine's credentials. So:

- Only `https` is allowed by default, and only to public addresses.
- Private, loopback, link-local, carrier-grade-NAT and reserved ranges are
  refused, in both IPv4 and IPv6, including addresses that disguise one as the
  other.
- The address checked is the address dialled, so a name that resolves
  differently a moment later gains nothing.
- Redirects are followed only within the same host, and never from `https` to
  `http` — a redirect elsewhere would hand your Gotify or Slack token to
  whoever asked for it.

That default refuses the most common self-hosted setup there is: a Gotify on
your LAN, or one reachable only over a Tailscale address. **Settings → Trusted
hosts** is how you allow it, and it needs instance administration. Add a host
(`gotify.lan`), a suffix (`.lan`), an address, or a range (`100.64.0.0/10` for
Tailscale), and that destination becomes reachable — over plain `http` as well,
since a LAN service usually has no certificate. Each entry is an exception you
chose, and every use of one is written to the log.

---

## Signing in

Three ways in, and they interlock rather than sitting side by side.

**Password.** Always available, argon2id-hashed, throttled per account and per
IP.

**Authenticator app (TOTP).** Turn it on from **Settings → Account security**:
scan the QR code and type the six-digit code it shows, to prove the app received
the key. Turning two-step verification off requires your password, so a stolen
but still-signed-in session cannot quietly remove it.

The secret is stored encrypted (see `TOTP_ENCRYPTION_KEY`), which is the best
available: the server has to reproduce codes from it, so it cannot be hashed.
What that protects is a database dump or a stray backup, not an attacker who
already owns the running application. A submitted code is also refused if its
thirty-second step has already been used, so a code captured in transit cannot
be replayed inside its own window.

**Passkeys and security keys.** Register as many as you like — a phone, a
laptop, a hardware key — and name them so a lost one can be revoked without
touching the others. A passkey signs you in on its own, and satisfies the second
factor when you sign in with a password.

Passkeys are bound to the host in `APP_URL`, which is the relying-party id.
Changing that host invalidates every registered passkey; that is the mechanism
working, not a fault.

**Recovery codes.** Ten of them, issued the first time you set up *either*
factor — an authenticator app or a passkey — and shown once. Each works once.
They are hashed like passwords, so nobody can read them back to you; regenerate
a set from **Settings → Account security** (it invalidates the old one) and
store them somewhere other than the device they are protecting. Removing your
last second factor clears them, because there is then nothing to recover into.

**The order of a sign-in.** Once *either* second factor is set up, a correct
password alone is not a sign-in: it parks the browser on a two-step verification
page with a ten-minute window, and until the factor is presented the session
holds no user at all — every authenticated page treats that browser as
anonymous. Second-factor attempts have their own throttle, separate from the
password one.

**If you lose everything.** Use a recovery code — they work whichever factor you
lost. If those are gone too, an operator with database access is the only way
back; there is no email-based bypass of a second factor, because one would make
the second factor optional for anybody who can read your mailbox.

### Audit log

Every sign-in, failed sign-in, sign-out, password reset, second-factor change,
passkey change, session revocation, role change and instance-setting change is
recorded with who did it, to whom, when, and from which address and browser.

Who sees what is decided in the repository layer, not by the route: an instance
administrator sees the whole instance at **/audit**, a household Owner sees their
own household's events, and everybody else gets a 403. Entries keep the address
and name as they were at the time, so deleting an account does not blank out its
history. `maintenance:prune` removes entries older than
`AUDIT_LOG_RETENTION_DAYS`.

### Active sessions

**Settings → Account security** lists every browser signed in as you — device,
address, when it started and when it was last seen — with the current one
marked. Revoking one deletes its session row, so that browser is anonymous on
its very next request; there is no window in which a revoked session still
works. A completed password reset revokes every session on the account, since
the reset may well have been prompted by somebody else having one.

---

## The API

Everything the web interface can do to a subscription, `/api/v1` can do too —
including editing, field by field, rather than delete-and-recreate. Both call
the same services, so the billing-cycle rules, the trial checks and the
ISOLATED-mode owner override behave identically whichever door a request comes
through.

### Tokens

Issue one under **Settings → API tokens**. A token looks like
`rnv_<public id>_<secret>`; only a hash of the secret is stored, so it is shown
once and cannot be recovered. Choose **read-only** unless something genuinely
needs to make changes.

A token narrows and never grants. The request runs under the household role of
the account that issued it, so a Viewer's "read and write" token still cannot
write — it fails the same permission check the browser would. A read-only token
is refused any unsafe method outright, before a route runs.

```bash
curl -H "Authorization: Bearer rnv_..." http://localhost:8080/api/v1/subscriptions
```

Session cookies are **not** accepted. That is deliberate rather than an
oversight: because no ambient browser credential can authenticate an API
request, there is nothing for a cross-site request to forge, which is what makes
it safe for these paths to be exempt from CSRF tokens.

### The contract

`openapi/openapi.yaml` is the source of truth — written by hand, served verbatim
at **/api/v1/openapi.yaml** (and converted at **/api/v1/openapi.json**), and
checked against the application's own route table in CI **in both directions**.
Add an endpoint without describing it and the build fails; describe one that does
not exist and the build fails too.

A few things worth knowing before writing a client:

- **Money is an integer in minor units** beside its currency: `price_minor:
  1999` with `currency: "GBP"` is £19.99. Never a decimal — 19.99 is not
  representable as a binary float, which is most of the reason this application
  stores integers everywhere.
- **`PUT` replaces; there is no `PATCH`.** To change one field, `GET` the
  resource, edit the representation and `PUT` it back. A partial body would
  clear the fields it omitted.
- **`reminder_days` has three states**, and they are all different: omitted or
  `null` means "use my notification preference", `[]` means "never remind me
  about this one", and `[30, 7, 1]` sets lead times for this subscription alone.
- **A 404 means "no such row, or not yours"**, deliberately indistinguishable,
  so probing for another household's ids tells you nothing.
- Errors are JSON: `{"error": {"status", "title", "message", "errors"}}`, where
  `errors` maps a field name to a message and appears on a 422.

Start with `GET /api/v1/me`, which reports the role, the isolation mode and the
resolved permission list for the token you are holding.

**Not in version 1:** shared-cost splits, scheduled price changes and the usage
counter. All three are editable in the web interface, and all three are
sub-resources with rules of their own — a split has to total its shares, a
scheduled price has an effective date that interacts with the price history —
so rather than fix a shape now that would be awkward to change once clients
depend on it, they read as part of a subscription (`split_mode`, `usage_count`,
`usage_rating`) and are written through the web interface. The OpenAPI document
says so too.

---

## Importing

**Import** in the navigation takes a CSV or JSON file through four steps: upload,
map the columns, preview, commit. Nothing is written until the last one.

The preview is the point. Every other tracker's export uses different column
names and different words for a billing cycle, so the mapping screen shows what
was guessed and lets you correct it, and the preview then shows every row as it
will be created — with any row that cannot be imported marked and explained.
Validation there is the *same* code that validates the form, so a row the
preview calls valid is one that will be written.

- CSV (comma, semicolon or tab separated, with or without a byte-order mark) and
  JSON, up to 2000 rows.
- Presets for this application's own export, Wallos and a generic spreadsheet,
  plus automatic detection that handles most files on its own. A preset is only
  a starting point — you confirm the mapping before anything happens.
- Prices go through the same parser as the form, which knows that `1,234.56` and
  `1.234,56` are the same number and how many decimal places a currency has.
- Dates: `YYYY-MM-DD` is tried first because it is unambiguous. `01/03/2026` is
  read **day-first**, so a file written the American way will import dates that
  are wrong — which the preview shows you before you commit.
- Categories are created by name if they do not already exist; so are tags.

Imported rows land in your household with the ordinary owner rules, exactly as if
you had typed them in.

---

## Backup and restore

**Settings → Backup and restore** downloads a ZIP of everything the household
has: subscriptions, categories, tags, budgets, logos and attached invoices, as
JSON plus the original files. Nothing in it needs a database to read.

It is built through the scoping layer rather than by dumping tables, which means
it contains what *you* can see — on an ISOLATED instance, your own subscriptions
and not other members'. Deliberately **not** included: user accounts, passwords,
API tokens, the audit log and instance-wide settings. Those belong to the server
rather than to the household, and a household Owner who could round-trip them
would be able to reconfigure the instance through the backup screen. Members are
referenced by email address, which is enough to restore a subscription to the
right owner and not enough to create an account.

Restoring is **additive**: it creates rows and never deletes or overwrites any,
so restoring the same file twice leaves two of everything. A member who is still
in the household keeps their rows; one who has gone hands theirs to whoever is
doing the restore. The household's own name is in the archive but is not applied
— you are restoring into a household that already exists and already has a name
somebody chose.

An archive is treated as hostile input however it was produced. Entry names are
checked against a strict pattern before anything is extracted — an archive
containing a `../` path is refused whole, not sanitised — and every logo and
invoice inside it is re-validated by its magic bytes exactly as an upload is.

Both routes need the household-management role. An export is every price and
note in the household in one file, and a restore adds rows in bulk.

> A backup archive is not a substitute for backing up the database and the
> `var/` directory. It is what makes a household portable between instances.

---

## Calendar feed

Subscribe to **/api/v1/calendar.ics?token=…** in any calendar application and
you get renewals for the next year, the day each trial converts, and the last
day to cancel each subscription before its notice period makes that impossible.
The third is the one a calendar is genuinely better at than a notification: it
is a deadline, and seeing it a fortnight out is the whole point.

The URL is shown, ready to copy, under **Settings → API tokens**.

Every event is a whole-day event — a billing date is a date, not an instant —
and its identifier is stable, so a client that refetches on a timer recognises
what it already has instead of duplicating it.

This is the one endpoint that takes its credential in the URL, because a calendar
client cannot send an `Authorization` header. It compensates by accepting
**read-only tokens only**: a write-capable token is refused rather than quietly
downgraded, because a credential that can change data does not belong in a URL
that will sit in a calendar application for years.

---

## Invoices and receipts

Attach a PDF or an image to a subscription from its **money** page, optionally
tagged with the billing period it covers. Attaching needs the same access as
*editing* the subscription, not merely seeing it — which on an ISOLATED instance
means its owner, since an attachment inherits the subscription's visibility and
one you could not see would be of no use to anybody.

Unlike logos, these are **not** under `public/`. An invoice has an address and a
card number on it, so the files live outside the web root — under `var/` by
default, which docker-compose already keeps on a persistent volume — and the only
way to read one is a route that asks the same questions about the subscription
that every other read asks. Another household gets a 404; so does a member who
cannot see the subscription on an ISOLATED instance.

What a file *is* comes from its contents, never from its name or the type the
browser declared: PDF, PNG, JPEG, WebP and GIF are accepted and everything else
is rejected, so a `.pdf` full of PHP does not get stored. The name on disk is
chosen by the application, the original is kept for display and for the download
filename, and files are served with the detected type, `nosniff` and
`Content-Disposition: attachment`.

Set the ceiling with `UPLOAD_MAX_ATTACHMENT_BYTES` (10 MB by default) and the
location with `ATTACHMENT_DIRECTORY`.

---

## Language

Every string the application shows — pages, validation messages, flash
messages, reminder emails, the calendar feed, even the handful of messages its
JavaScript can produce — comes from a catalogue keyed by name. `en` is the base
and the only one that ships.

Each account chooses its own language under **Settings → Appearance**, or
follows the instance (`APP_LOCALE`). Somebody who is not signed in gets the best
match for their browser's `Accept-Language`, and the instance default when there
is no match. Dates and money follow the same choice: they are formatted by ICU,
so a French reader gets `4 sept. 2026` and `12,99 £` without a second setting.

### Adding a language

1. Copy `translations/en.php` to `translations/<locale>.php` — `fr`, `de`,
   `pt_BR`, any ICU locale code.
2. Translate the right-hand side of each entry. Leave the keys alone.
3. Run the check:

   ```bash
   php bin/console i18n:check
   ```

   It compares every catalogue with the base in both directions and exits
   non-zero on any difference. A missing key is the obvious one; an *extra* key
   is the one worth catching, because it means either a message nothing renders
   any more or a typo that has left a page quietly falling back to English. CI
   runs the same command.

Two things to know while translating:

- **Messages are ICU MessageFormat.** Anything in braces is an argument, and a
  count that reads differently in the singular takes a `plural` block. Your
  language's rules are yours: Polish has three forms and Arabic six, and the
  catalogue is where you say so.

  ```php
  'notice.days' => '{count, plural, one {# day} other {# days}}',
  ```

- **A regional catalogue may be partial.** `fr_CA.php` needs only what Canadian
  French says differently; anything it does not define falls back to `fr`, and
  then to `en`. Only `en` has to be complete.

Nothing else needs changing. The language list on the settings page is the set
of files in `translations/`.

## Calendar

**Calendar** shows the month: every renewal on the day it falls, and the day
each free trial starts charging. The figures come from the same forecast the
budgets and the twelve-month view use, so a scheduled price rise shows the
amount that will actually be taken rather than today's price.

It pages forward as far as the forecast goes and no further back than this
month — this application tracks what is due, not a ledger of what was paid, so
a calendar of last year would be an invention.

Weeks start on Monday or Sunday, per account, under **Settings → Appearance**.
It is a preference rather than a property of the locale on purpose: `en_GB` and
`en_US` disagree, and plenty of people read a Monday-first calendar in an
American locale because that is how their working week runs.

## Making it yours

All of this is per account, under **Settings → Appearance**, and none of it
needs any permission: it changes what one person sees and nothing that anybody
else does.

- **Theme** — system, light or dark.
- **Language** — see [Language](#language).
- **Week start** — used by the calendar.
- **List density** — comfortable or compact. Compact is for the account with
  ninety subscriptions; it is the same markup with less padding, so a screen
  reader sees no difference.
- **Open on** — the page you land on when you open Renovo. The dashboard,
  the subscriptions list, the calendar, budgets, the forecast or the statistics.
- **Dashboard cards** — reorder them by number and untick the ones you do not
  want. A card added by a later version appears in its default place rather
  than silently going missing.

### Saved views

Filter the subscriptions list however you like, give the result a name, and it
appears above the list as a link. What is stored is the query string the list
itself produced, re-parsed through the same value object that reads a URL — so
a saved view is a bookmark the instance keeps for you, and a tampered one can
no more reach the database than a tampered link can.

### Keyboard shortcuts

Press **?** anywhere for the list. In short: **n** opens the quick-add dialog,
**/** jumps to the search box, and **g** then a letter goes somewhere — `g d`
for the dashboard, `g s` for subscriptions, `g c` for the calendar.

The quick-add dialog loads the real subscription form rather than a shortened
copy of it, so there is one definition of what a subscription needs. Everything
a shortcut does is also a link or a button on the page: a browser with
JavaScript off loses the convenience and none of the function.

### Logos

Upload one on the subscription form, or give the subscription its **Website**
and Renovo will ask that site for its own icon — `/apple-touch-icon.png`,
`/favicon.ico` and the two other conventional paths, in that order.

It asks the site, never a logo service: every icon service works by being told
which brands you are interested in, and a household's list of subscriptions is
the thing this application exists to keep. The request goes through the same
guarded HTTP client as notifications and webhooks, https only, so a `Website`
pointing at a private address is refused rather than fetched.

Results are cached per domain for thirty days, failures for seven. Twenty
households with the same streaming service cost one request between them, and a
site with no icon is not asked again on every save. Each subscription still
gets its own copy of the image, so removing one subscription's logo can never
blank another's.

## Health and metrics

Three endpoints, outside every login and exempt from the first-run wizard, so
they answer honestly on a container that has never been configured.

| Endpoint | Answers | Open? |
| --- | --- | --- |
| `/healthz` | Is this process alive? Touches nothing else. | Yes |
| `/readyz` | Should it be sent traffic? Database, schema, scheduler. | Yes |
| `/metrics` | Prometheus exposition. | No — see below |

**Liveness deliberately does not touch the database.** An orchestrator restarts
what fails a liveness probe, so a probe that queried the database would turn a
thirty-second blip into a restart loop. Readiness is the one that asks: it
answers 503 when the database is unreachable, when no migration has been
applied or one did not finish, or when the scheduler has not completed a run in
two days — a scheduler container that died stops every reminder and changes
nothing else a page would show.

The compose file points nginx's healthcheck at `/readyz`.

`/metrics` carries counts of accounts, households, subscriptions, notification
outcomes, rate freshness and requests by status class. Those are not secrets,
but they describe an instance, so it is closed by default: set `METRICS_TOKEN`
and give the same value to the scrape job as a bearer credential. An instance
administrator signed in to a browser can also read it. Anybody else gets 404 —
there is nothing to be gained by confirming that the endpoint exists.

Setting a token also starts counting requests by status class, which costs one
small `UPDATE` per request. Leave it unset if nothing scrapes this instance and
nothing is written at all.

## Demonstration mode

**Settings → Instance → Read-only demonstration** closes the whole instance to
writes: every form, every API endpoint, for everybody. It is a single switch
because a demo is what the whole server is for while it is on.

Seed something worth showing first:

```bash
php bin/console demo:seed
```

That creates a `demo@renovo.local` account in its own household with six
invented subscriptions, one of them a trial about to convert, and prints a
random password once. The subscriptions are written through the ordinary
services, so the dashboard's figures are computed exactly as they would be for
a real household.

Two things are still allowed while demo mode is on: signing in and out, and an
instance administrator saving the instance settings form — otherwise the switch
could not be turned off again without database access.

Nothing special happens to reads. The demo account is an ordinary member of its
own household, so the scoping layer shows it the seeded data and nothing else,
exactly as it would for anybody. An instance that also hosts real accounts
keeps them private, and there is a test that says so.

## Security

- Passwords hashed with **argon2id**, falling back to bcrypt only where the PHP
  build lacks it; an old hash is upgraded transparently on the next sign-in.
- **CSRF tokens** on every state-changing request, checked by global middleware
  so a new POST route is protected the moment it exists. htmx requests carry the
  token in a header set once on the page body.
- Sessions are **server-side, in the database**, with secure, http-only,
  same-site cookies and a fresh id on sign-in.
- Login, password reset and the **second-factor step** are throttled per account
  and per IP, so neither brute-forcing one account from many addresses nor
  spraying many accounts from one address gets far. The second factor has its own
  budget rather than sharing the password's.
- A user who has proved their password but not their second factor is **not
  signed in**: the pending state lives under its own session key and expires, so
  no authenticated route can mistake it for a session.
- **TOTP secrets are encrypted at rest** and a used time-step is remembered, so a
  code cannot be replayed within its validity window. A secret that no longer
  decrypts — a rotated key — fails the sign-in closed and says so in the log,
  rather than throwing an error at somebody holding a correct code.
- **Recovery codes are hashed** like passwords, each works once, and they are
  issued for whichever second factor an account has — a passkey earns them just
  as an authenticator app does, because losing your only passkey locks you out
  exactly as hard.
- **WebAuthn credentials** are verified with a maintained library
  (`web-auth/webauthn-lib`) running its full ceremony: origin, challenge,
  relying-party hash, signature and a sign counter that may not go backwards. The
  user handle stored in an authenticator is random, never the account's id.
- **Revoking a session takes effect immediately** — the row is deleted, and the
  session handler is what every request consults.
- **An audit log** records authentication and administrative events with actor,
  target, address and user agent; reads are scope-checked in the repository, so a
  route cannot widen them.
- Sign-in and password-reset responses are **identical whether or not the
  address exists**, so neither form can be used to enumerate accounts.
- Verification and reset tokens are stored only as SHA-256 hashes, are
  single-use, and expire.
- Logos are **uploaded, never fetched**: the image type is detected from the
  file itself, the name is replaced with a random one, and nginx refuses to
  execute anything in the upload directory.
- **API tokens are stored as hashes**, split into a public identifier and a
  secret so that verifying one is a single indexed read rather than a scan. They
  can be given an expiry, revoked at any moment, and can only ever be weaker
  than the account that issued them.
- The **API refuses session cookies**. No ambient browser credential can
  authenticate an API request, which is what makes the CSRF exemption on those
  paths safe rather than a hole; the exemption itself additionally requires a
  bearer token to be present.
- The **calendar feed** is the only endpoint that takes a credential from the
  URL, because a calendar client cannot send a header. It is wired to its own
  middleware, is read-only, and refuses a write-capable token outright.
- **Invoices and receipts live outside the web root** and are streamed only
  through a permission-scoped route. Their type is read from the file's magic
  bytes rather than from its name or declared type, the stored name is chosen by
  the application, and they are served with `nosniff` and as an attachment.
- A **backup archive is untrusted input**. Entry names are validated against an
  allow-list before anything is extracted — a `../` path is refused whole rather
  than sanitised — and every file inside is re-validated by its contents exactly
  as an upload is.
- An **imported file is staged under an id held in the session**, never in a URL
  or a form field, so one user cannot step through another's upload. The preview
  writes nothing at all, including the tags it would otherwise create.
- Every outbound request goes through **one HTTP client** with enforced
  timeouts, a response-size cap and proxy support; requests to URLs a *user*
  supplied additionally go through an **SSRF guard** that refuses private and
  reserved addresses, pins the address it checked against DNS rebinding, and
  will not follow a redirect to another host. See
  [Where notifications may be sent](#where-notifications-may-be-sent).
- Outbound notifications are **rate-limited per user and per subscription**, so
  a mistake in an alert rule cannot turn the instance into a traffic source.
- Channel credentials are **never rendered back into a form** and never written
  to a log: a stored token shows as configured and can be replaced.
- Templates escape all output.

---

## Licence

MIT.
