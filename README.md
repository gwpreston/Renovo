# Renovo

A self-hosted tracker for subscriptions and recurring bills. Multi-user,
permission-scoped, with per-currency totals, upcoming-renewal windows, notice
periods and a light/dark interface.

Built in phases. **Phase 1 — foundation, auth, roles and core tracking — is
complete.** See `PHASE.md` for what is in scope now and `SPEC.md` for the whole
plan.

---

## Requirements

- **Docker** and the Compose plugin (the supported way to run it), or
- **PHP 8.2+** with `pdo_pgsql` or `pdo_mysql`, `intl`, `curl`, `zip`, plus
  Composer and a **PostgreSQL 14+** or **MySQL 8 / MariaDB 10.6+** server.

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
| `scheduler` | Runs the daily console command (no scheduled work in this phase)   |
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
| `SESSION_KEY`               | Required. `openssl rand -hex 32`. Rotating it signs everybody out. |
| `DB_DRIVER`                 | `pgsql` or `mysql`.                                           |
| `DB_HOST`                   | Host-side value for CLI tools; containers always use `database`. |
| `APP_URL`                   | Used to build links in emails — set it to the real URL.       |
| `SESSION_COOKIE_SECURE`     | Leave `true` unless you are serving plain HTTP on a trusted network. |
| `AUTH_MAX_ATTEMPTS_PER_*`   | Login and reset throttling, per account and per IP.           |

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
php bin/console reminders:run       # scheduler entry point; no work yet
php bin/console maintenance:prune   # expired sessions, tokens, throttle records
```

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
  Http/       The single shared outbound HTTP client.
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
   everywhere.

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
- **Totals are per currency and are never added together.** Combining them needs
  exchange rates, which arrive in Phase 2.

Renewal dates clamp at month end: 31 January plus one month is 28 or 29
February, and the original day of the month is restored as soon as a month is
long enough, so a subscription billed on the 31st does not drift to the 28th.

---

## Security

- Passwords hashed with **argon2id**, falling back to bcrypt only where the PHP
  build lacks it; an old hash is upgraded transparently on the next sign-in.
- **CSRF tokens** on every state-changing request, checked by global middleware
  so a new POST route is protected the moment it exists. htmx requests carry the
  token in a header set once on the page body.
- Sessions are **server-side, in the database**, with secure, http-only,
  same-site cookies and a fresh id on sign-in.
- Login and password reset are **throttled per account and per IP**, so neither
  brute-forcing one account from many addresses nor spraying many accounts from
  one address gets far.
- Sign-in and password-reset responses are **identical whether or not the
  address exists**, so neither form can be used to enumerate accounts.
- Verification and reset tokens are stored only as SHA-256 hashes, are
  single-use, and expire.
- Logos are **uploaded, never fetched**: the image type is detected from the
  file itself, the name is replaced with a random one, and nginx refuses to
  execute anything in the upload directory.
- Templates escape all output.

---

## Licence

MIT.
