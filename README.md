# Renovo

A self-hosted tracker for subscriptions and recurring bills. Multi-user,
permission-scoped, with multi-currency totals, budgets, a twelve-month forecast,
price history, free-trial tracking, shared-cost splitting, upcoming-renewal
windows, notice periods and a light/dark interface.

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

Still to come: OIDC/SSO, a versioned JSON API, and internationalisation.

See `PHASE.md` for what is in scope now and `SPEC.md` for the whole plan.

---

## Requirements

- **Docker** and the Compose plugin (the supported way to run it), or
- **PHP 8.2+** with `pdo_pgsql` or `pdo_mysql`, `intl`, `curl`, `zip`, `openssl`
  and `sodium` (the last two are bundled with most builds; they encrypt stored
  two-factor secrets and verify passkeys), plus Composer and a
  **PostgreSQL 14+** or **MySQL 8 / MariaDB 10.6+** server.

SQLite is not supported. Development and production run the same engine, so a
query that works locally works in production.

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

`docker compose up` starts five containers:

| Container   | What it does                                                       |
|-------------|--------------------------------------------------------------------|
| `web`       | nginx, serving `public/` on `${APP_PORT:-8080}`                    |
| `app`       | php-fpm running the application                                    |
| `database`  | PostgreSQL 16, also published on `127.0.0.1:5432` for host tooling |
| `migrate`   | Applies migrations once, then exits                                |
| `scheduler` | Runs the daily console commands — see [Commands](#commands)        |
| `mailpit`   | Catches outbound mail in development — http://localhost:8025       |

### Running against MySQL / MariaDB

```bash
# Also set DB_DRIVER=mysql and DB_PORT=3306 in .env.
docker compose -f docker-compose.yml -f docker-compose.mysql.yml up
```

---

## Running without Docker

```bash
composer install
cp .env.example .env          # then edit DB_* and set SESSION_KEY

vendor/bin/phinx migrate      # create the schema
php -S localhost:8080 -t public
```

Point a real web server at `public/` in production — nothing outside that
directory should be reachable over HTTP.

---

## Upgrading

**Back up your database first.** Then, from the project directory:

```bash
docker compose pull && docker compose up -d --build
```

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

---

## Commands

```bash
# Quality gates — all three must pass before a change is done
vendor/bin/phpunit           # tests
vendor/bin/phpcs             # PSR-12
vendor/bin/phpstan analyse   # static analysis, level 6

composer check               # all three in one go

# Database
vendor/bin/phinx migrate
vendor/bin/phinx rollback
vendor/bin/phinx seed:run    # a few starter categories (run after setup)

# Console
php bin/console list
php bin/console reminders:run       # send due reminders and budget alerts
php bin/console rates:refresh       # fetch and cache exchange rates
php bin/console maintenance:prune   # expired sessions, tokens, throttle, notification and audit records
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
  Service/    All business logic. The API in a later phase calls these too.
  Repository/ All persistence. The only place SQL is written.
  Security/   Scope, permissions, password hashing, CSRF, sessions.
  Persistence/ PDO wrapper and the PostgreSQL/MySQL differences.
  Service/ExchangeRate/  Rate providers behind one interface.
  Service/Notification/  Scanning for alerts, dispatching them once.
  Notification/ The Notifier interface, the registry, and one class per channel.
  Http/       The shared outbound HTTP client, and the SSRF guard for
              user-supplied URLs.
  Domain/     Money, cycles, roles, entities. No I/O.
templates/    Twig. No logic.
config/       Settings, container, middleware, routes.
migrations/   Phinx migrations and seeds.
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
