# CLAUDE.md

Operating manual for this repository. Read this before making changes. The full
feature spec and staged build plan live in `SPEC.md` (the phased-prompts
document); this file is the day-to-day conventions and guardrails.

## What this is

A self-hosted web app for tracking subscriptions and recurring bills:
multi-user, permission-scoped, with budgeting, trials, price history,
notifications, a versioned API, and i18n. Built in phases — see "Current phase"
below before adding anything.

## Tech stack (fixed — do not substitute without asking)

- **PHP 8.2+**, **Slim Framework 4** + **PHP-DI**.
- **Twig** templates; **htmx** for filter/sort/pagination without full reloads.
  Responsive, mobile-first CSS. No SPA framework.
- **Vite** builds the front-end assets: **Tailwind CSS 4**, a small JS bundle
  (Chart.js lazily, a handful of Lucide icons) and the **Inter** webfont
  vendored through Fontsource. Sources in `assets/`, output in `public/build/`
  with a manifest a Twig helper reads. Node is a *build* dependency only — the
  running application never uses it.
- **PDO** through a thin abstraction that runs on **PostgreSQL and MySQL/MariaDB**.
  **Local development uses the same engine as production (via Docker); no SQLite.**
  Prepared statements everywhere.
- **Phinx** migrations. **Composer** deps. Config via **environment variables**.
- **PHPUnit**, **PHP_CodeSniffer** (PSR-12), **PHPStan** (target level 6+).

## Non-negotiables

These are the rules that keep the app correct and safe. Breaking any of them is a
bug, even if the code "works".

1. **Money is integer minor units.** Never store or compute currency as a float.
2. **All persistence goes through the repository layer.** No raw SQL in
   controllers or services.
3. **All logic lives in service classes.** Controllers are thin; web controllers
   and API endpoints call the *same* services. No logic in templates.
4. **Every read and write passes through the central scoping layer**, which
   applies the current user's role and the instance data-isolation mode.
   Isolation must never depend on a route remembering to add a `WHERE` clause.
5. **All outbound HTTP goes through the shared HTTP client.** No ad-hoc `curl` or
   `file_get_contents`. The client enforces timeouts, max response size, proxy
   settings, and (for user-supplied URLs) SSRF protection.
6. **Permissions are enforced server-side on every route.** Hiding UI is not
   access control. A Viewer hitting a mutating endpoint gets **403**.
7. **Secrets come from env vars only.** Never hardcode; never commit `.env`.
8. **Nothing the browser loads comes from a third-party host.** No CDN link for
   a font, a stylesheet or a script. An instance may be on a LAN or behind a
   VPN with no route out, so every byte the page pulls is served from its own
   web root. Build time may use the network (npm, Composer); run time may not.
   `bin/console assets:offline-check` enforces this in CI over the templates,
   the build's sources *and* its output.

## Security baseline

- Passwords: `password_hash` with **argon2id** (bcrypt fallback).
- **CSRF tokens** on every state-changing form. Server-side validation on all
  input. **Escape all template output.**
- Secure, http-only, same-site session cookies.
- Rate-limit / progressively lock **login and password reset** (per-account and
  per-IP).
- **SSRF client** (used for logo fetch, webhooks, chat channels, OIDC, rate
  providers): https-only where applicable; reject private/loopback/link-local/
  reserved IPs (v4 and v6); pin resolved IP against DNS-rebinding; no redirects
  to disallowed targets; honour `HTTP(S)_PROXY` / `NO_PROXY`. A private target is
  only reachable if an admin adds it to the trusted-host/CIDR allowlist (opt-in,
  logged).

## Roles & data isolation (the core model)

- **Instance admin** (`is_instance_admin`): manages users and global settings.
  Cannot browse household data by default.
- **Household roles** (per membership): **Owner/Admin**, **Editor**, **Viewer**.
- **Isolation mode** (instance setting): **SHARED** (default) or **ISOLATED**.
  Every subscription stores both `household_id` and `owner_user_id`; visibility
  is applied centrally.
- **Shared-cost splits** are always visible to their participants regardless of
  isolation mode; each member's budget counts only their share.

## Project structure

```
src/          Controllers (thin), Services (logic), Repositories (persistence),
              the scoping layer, and the shared HTTP client.
templates/    Twig templates. No logic here.
translations/ One flat catalogue per locale; `en` is the base every other is
              measured against.
assets/       Front-end build sources (Tailwind entry, JS entry). Outside the
              web root and never served; only the build's output is.
public/       Web root (index.php, assets, build). Nothing else is
              web-accessible. `public/build/` is generated — never edit it, and
              never commit it.
migrations/   Phinx migrations + seeds.
bin/          CLI entry points (e.g. the scheduler command).
config/       DI, routes, settings.
tests/        PHPUnit tests.
```

## Commands

Adjust to match `composer.json` scripts once they exist; these are the canonical
intended commands — keep them working.

```bash
# Dependencies
composer install
npm install                  # front-end build only; not needed at run time

# Front-end assets (required before a page will render — the layout resolves
# its stylesheet through the build manifest)
npm run build                # compile into public/build/
npm run watch                # rebuild on change (the `assets` compose service)

# Run the app as it ships (each container serves its own image; no npm at run
# time). Postgres by default; MySQL variant documented in README.
docker compose up

# Run it for development — the working tree's web root plus the asset watcher
docker compose -f docker-compose.yml -f docker-compose.dev.yml up
./bin/dev-setup.sh           # does the above, and everything around it

# Database
vendor/bin/phinx migrate     # apply migrations
vendor/bin/phinx rollback    # roll back last (Postgres: transactional)
vendor/bin/phinx seed:run    # seed data

# Quality gates (must pass before a change is done)
vendor/bin/phpunit           # tests
vendor/bin/phpcs             # PSR-12 lint
vendor/bin/phpstan analyse   # static analysis

# Nothing is loaded from a third-party host (CI runs this after the build)
php bin/console assets:offline-check

# Scheduler (reminders, budget re-eval, rate refresh) — runs daily
php bin/console reminders:run
```

CI runs lint + static analysis + tests + a Docker image build on every PR, and
(from their respective phases) validates the OpenAPI spec and locale
completeness. Don't merge red CI.

## Database rules

- **Postgres or MySQL** everywhere — write portable SQL; test against both.
  Local development runs the same engine as production (via Docker) for dev/prod
  parity. Do not use SQLite.
- Every migration ships an explicit **`down()`**. Migrations are **transactional
  where the engine supports it** (Postgres has transactional DDL; for MySQL keep
  each migration small and idempotent and note manual-rollback steps).
- Applied version is tracked so partial migrations are detectable. Document a
  single upgrade command and always tell operators to **back up first**.

## Conventions for common additions

- **New notification channel:** implement the `Notifier` interface, register it
  in the registry, add its per-user config fields. No changes to calling code.
  User-URL channels must use the SSRF client; SMTP is exempt (not a URL fetch).
- **New locale:** copy the `en` base, translate keys, run the completeness script
  (it must pass in CI — no missing/extra keys).
- **New migration:** one concern per migration, with a working `down()`; verify
  on Postgres and MySQL.
- **New front-end dependency:** install it from npm and let the build vendor
  it. Never add a `<script>` or `<link>` pointing at a CDN — not even
  temporarily. A large library goes behind a dynamic `import()` so it becomes a
  chunk of its own rather than weight on every page; see `assets/js/charts.js`.
- **New icon:** add it to the map in `assets/js/icons.js`. Importing Lucide's
  index instead would put a thousand icons in the bundle to use one.
- **New feature touching data:** it must respect roles + isolation via the
  scoping layer, and be covered by tests, including a permission test.

## Testing expectations

Every change ships with tests. High-value areas that must stay green: billing
normalisation, trial-conversion timing, price-history current-price resolution,
budget projection/threshold logic, reminder idempotency, the SSRF client's
private-IP rejection + allowlist override, and permission/isolation
(Viewer 403, ISOLATED hides others' rows).

## Guardrails — don't do these

- Don't add floats for money, raw SQL in services, or logic in controllers/
  templates.
- Don't fetch a URL outside the shared HTTP client.
- Don't make the browser load anything from a third-party host, and don't edit
  or commit `public/build/` — it is the build's output.
- Don't add a query path that bypasses the scoping layer.
- Don't build features from a later phase into an earlier one — leave clean
  seams instead of stubs.
- Don't add **bank/transaction sync** (Plaid/GoCardless/Firefly) — it's
  explicitly out of v1.
- Don't use SQLite — develop and test against Postgres or MySQL, the same
  engines used in production.

## Current phase — READ PHASE.md FIRST

Before planning or writing any code, read PHASE.md. It defines the ONLY scope
you may build right now. Do not build features from a later phase, even if they
appear in SPEC.md — leave clean seams instead. If PHASE.md and this file seem to
conflict about scope, stop and ask.

## Working style

For any non-trivial change, **output a short plan first** — data-model changes,
new/changed files, integration points, migrations — and wait for approval before
writing implementation code. When a detail is genuinely ambiguous, pick a
sensible default, note the assumption, and continue. Consult current official
docs (Slim 4, Twig, Phinx, the mailer, each channel's format) rather than memory
for tricky parts.