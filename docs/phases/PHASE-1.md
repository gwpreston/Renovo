# PHASE.md — current build state

Single source of truth for what to build **right now**. Update it as work
progresses. SPEC.md = full plan · build-guide = file map · CLAUDE.md = standing
rules. When you start this phase, copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 1 — Foundation, auth, roles & core tracking.**

Goal: a working, multi-user, permission-scoped subscription tracker with auth,
running under Docker on the production database, with migrations and CI in place.

## Depends on
Nothing — this is the first phase.

## In scope this phase (build ONLY these)
- Project scaffold, Composer, quality-gate config (PHPCS/PHPStan), folder tree.
- **Database: PostgreSQL and MySQL/MariaDB via a thin PDO abstraction. Local
  development uses the SAME engine as production, run via Docker. SQLite is NOT
  used anywhere.** Prepared statements throughout.
- Phinx migrations: transactional where the engine supports it, every migration
  has an explicit `down()`, applied version tracked. Documented upgrade command
  + "back up first" note.
- Full env-var config (`.env` + `.env.example`): DB, app URL, SMTP, session key.
- Slim bootstrap: front controller, settings-from-env, PHP-DI container,
  middleware stack, routes file.
- Shared outbound HTTP client (basic: timeouts, max size, proxy). Single
  outbound path. No user-supplied URLs fetched yet.
- Docker: app (php-fpm + nginx) + database + a scheduler container with an empty
  placeholder command. One-command start. Bare-metal run steps documented.
- CI: PHPCS + PHPStan + PHPUnit + Docker image build on PRs.
- Auth: sign up, email verification, login/logout, password reset, secure
  sessions, brute-force rate-limiting on login + reset (per-account and per-IP).
- Roles, permissions & isolation:
  - Instance admin (`is_instance_admin`), seeded for the bootstrap account.
  - Household roles per membership: Owner/Admin, Editor, Viewer (enforced
    server-side).
  - Isolation instance setting: SHARED (default) or ISOLATED. Every subscription
    stores `household_id` and `owner_user_id`.
  - ONE central scoping layer all reads/writes pass through.
- Subscriptions: name, price (minor units), currency, cycle (weekly/monthly/
  quarterly/yearly/custom-N-days), next payment date, category (single), tags
  (many), optional logo (stored, not fetched), member/payer, notes,
  **notice-period as a first-class field**, **one-off/lifetime type**. Full CRUD;
  auto-advance next payment on cycle.
- Dashboard/stats (basic): monthly/yearly normalisation, per-currency subtotals,
  upcoming renewals (7/30 days). Sort, filter, global search. Theme (light/dark).
- First-run setup wizard v1: bootstrap admin, base currency, isolation mode.

## Explicitly OUT of scope until a later phase (leave seams, do NOT stub)
- Exchange-rate conversion, combined totals, budgets, trials, price history → **Phase 2**
- Notifications, reminders, scheduler logic → **Phase 3**
- Passkeys, TOTP, OIDC/SSO, audit log, session UI → **Phase 4**
- JSON API, OpenAPI, import/export, iCal, attachments → **Phase 5**
- i18n, calendar view, metrics, demo mode, server-side logo fetch → **Phase 6**

## Status
- [x] Scaffold + tooling + quality-gate config
- [x] Docker (app + database + scheduler placeholder), one-command start
- [x] Slim bootstrap (front controller, settings, DI, middleware, routes)
- [x] DB abstraction (Postgres + MySQL) + Phinx configured
- [x] Base migrations (users, households, memberships, sessions,
      instance_settings, categories, tags, subscriptions, subscription_tags)
      — plus auth_tokens and auth_attempts, see deviations below
- [x] Shared HTTP client (basic)
- [x] Auth (signup, verify, login, reset, sessions, rate-limit)
- [x] Roles + isolation + central scoping layer
- [x] Subscription CRUD + list/filter/search
- [x] Dashboard/stats + theme
- [x] First-run wizard v1
- [x] CI pipeline defined (lint + static analysis + tests on Postgres AND
      MySQL + migration rollback check + Docker image build). Not yet observed
      running — this repository has no remote.
- [x] Tests: normalisation, scoping/permissions (Viewer 403, ISOLATED hides
      rows), auth smoke — 71 tests, 195 assertions, green on both engines

## Decisions & deviations made during the build

### Scope of the schema
- **Two tables were added that the checklist above did not name**:
  `auth_tokens` (email verification and password reset, one table with a
  `purpose` column rather than two near-identical ones) and `auth_attempts`
  (the per-account and per-IP throttle ledger). Both are required by in-scope
  features that had no table listed.

### Where things live (matters for later phases)
- **The scoping layer is `src/Repository/AbstractScopedRepository.php`**, with
  the request's `Scope` built in `src/Security/ScopeFactory.php` by
  `AuthenticationMiddleware`. Every scoped method takes a `Scope` as a required
  argument and injects the predicate itself; there is no method that omits it.
  Writes are scoped identically to reads and raise `ScopeViolationException`
  (rendered as 404) when they match nothing.
- **Permissions**: `src/Security/PermissionService.php` is the only place roles
  map to answers; `RequirePermissionMiddleware` is attached per route in
  `config/routes.php`.
- **Categories and tags are household-scoped but have no owner column**, so
  ISOLATED mode does not hide them. They are labels, not financial data.

### Normalisation decisions the tests now pin
- Day-based cycles (weekly, custom-N-days) use a **365.25-day year**. A 7-day
  custom cycle equals weekly exactly; a 28-day cycle deliberately differs from
  monthly. Changing this factor changes every historical figure.
- The monthly figure is always derived from the annual one, so both columns of
  a report agree. Division rounds **half away from zero**.
- **One-off and lifetime entries are excluded** from recurring totals and shown
  separately.
- **Calendar cycles clamp at month end** and restore the original day-of-month
  via the `anchor_day` column, so a subscription billed on the 31st does not
  drift to the 28th after one February.
- **Currency minor-unit exponents are looked up, not assumed** (JPY 0, KWD 3).
- Totals are **per currency only** — no combined figure until rates arrive.

### Other assumptions
- Local development uses the production DB engine (PostgreSQL or MySQL); SQLite
  is not used.
- **Composer's platform is pinned to PHP 8.2.0** so the lock file installs on
  the minimum supported version, not just on whatever the developer is running.
- Sessions are stored in the database via a PDO handler, so the app container
  is stateless. `PHPStan runs at level 6` and `phpcs` at PSR-12; Phinx files are
  exempted from the namespace sniff because Phinx loads them globally.
- Registration is open by default and can be closed from instance settings.
- The bootstrap admin created by the wizard is pre-verified; there is nobody to
  send a confirmation link to yet, and an undeliverable email would lock the
  only administrator out.
- Signup creates the user's own household with them as Owner/Admin. Inviting
  others into a household has no UI yet — roles can be changed in settings once
  a membership exists. Nothing in scope required an invite flow.
- Cycle auto-advance runs lazily when the dashboard or list is viewed, not on a
  schedule: the scheduler exists but has no work until Phase 3. **It is gated on
  the viewer being able to write** — a Viewer's GET must not perform an UPDATE
  just because their household predicate happens to match. Dates catch up the
  next time somebody who can write looks at them.
- **The test suite truncates every table**, so it refuses to run against a
  database whose name does not contain `test` unless `CI` or
  `ALLOW_DESTRUCTIVE_TESTS=1` is set. (Learned the hard way during this phase.)

### Bugs this phase's tests and verification caught
- The route-group callable was a `static` closure, which Slim cannot bind to the
  container — every request would have failed. Found by the functional test.
- `App\Security\...` inside `config/routes.php` resolved against the `Slim\App`
  import instead of the root namespace.
- `SystemClock` used `new DateTimeZone(...)` as a parameter default, which
  PHP-DI cannot compile — fatal in production (compilation on), invisible in
  development (compilation off). Found by the scheduler container.
- The MySQL compose override merged its `ports` list with the base file's,
  binding one host port twice.
- `curl_close()` in the shared HTTP client is deprecated in PHP 8.5 (a no-op
  since 8.0), as was `PDO::MYSQL_ATTR_INIT_COMMAND` in the MySQL platform. Both
  removed — the project supports 8.2 through 8.5.
- `CsrfMiddleware` was ordered to run *before* body parsing. It happened to work
  because the SAPI pre-populates `$_POST` for form encodings, but it would have
  broken the moment a JSON endpoint arrived in Phase 5.
- A Viewer's GET on the list or dashboard performed an UPDATE via the cycle
  auto-advance. Now gated, and covered by a test that fails without the guard.
- **Every route with a placeholder was broken** (`/subscriptions/{id}/edit` and
  six others): Slim's default invocation strategy passes the whole route-
  arguments *array* as the third argument, while the controllers declared
  `string $id`, so each was a TypeError on every request. Fixed by setting
  `RequestResponseNamedArgs` on the route collector in `config/bootstrap.php`,
  before any route is registered.

  The permission tests hit those routes but only ever reached the middleware —
  a Viewer is refused before the controller runs — so nothing in the suite had
  executed a controller taking a route argument. Reported by the user, not
  caught here. There are now tests that drive every placeholder route as a user
  who is allowed through, and they fail without the fix.

### Verified by hand as well as by tests
The logo upload path and the shared HTTP client had no execution coverage, so
both were exercised against the running stack: a PNG uploads, is stored under a
random name, is served by nginx as `image/png` and renders in the list, while a
PHP script renamed `.png` is rejected with a 422 and never written. The HTTP
client fetches a real URL, aborts mid-stream at its size cap, and honours its
connect timeout.

## Definition of done — met

Verified on 2026-09-13:

- `docker compose up` starts clean; all five containers healthy, the wizard is
  reachable, and a full flow (setup → add subscriptions → dashboard → htmx
  filtering → registration email) works end to end.
- Migrations apply to a fresh database, roll back to zero, and reapply — on
  **both** PostgreSQL 16 and MySQL 8.4.
- `phpcs`, `phpstan analyse` (level 6) and `phpunit` all pass; the suite is
  green against both engines.
- The app runs with the container **compiled** (production mode), not only in
  debug.
- No later-phase feature was stubbed. Seams left deliberately: the shared HTTP
  client exists but nothing passes it a user-supplied URL (SSRF hardening lands
  with its first consumer); `bin/console reminders:run` exits cleanly with no
  reminder logic; `owner_user_id` on every subscription is the hook Phase 2's
  per-member budgets need; no rate, budget, trial, price-history, notification
  or shared-split tables exist.

Next: copy `PHASE-2.md` over this file and commit.