# Changelog

All notable changes to Renovo are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Before upgrading, **back up your database**, then run `vendor/bin/phinx migrate`.

## [Unreleased]

### Added

- **Scenario planner** — a Scenario tab on the Forecast screen
  (`/forecast/scenario`) answers "what if I cancelled these, and moved that one
  to another plan?" before anything is changed. Set any running subscription to
  Cancel or Change price (a new amount, and optionally a new billing cycle), and
  it shows the monthly and yearly run-rate saving, the saving over the next 12
  months charge by charge, and the date each saving starts — a charge a notice
  period has already committed is named, never counted as saved. The scenario
  lives in the address, so it can be bookmarked and works without script.
  Reach it from **If you cancelled**, from **Plan a scenario** on the
  subscriptions list's selection bar (open to every role that can read the
  list) and from **What if I cancelled this?** on a subscription's cost page.
  Each row links to the existing cancel action or price form, prefilled; the
  planner itself changes nothing. *Phase 31.*

### Fixed

- **If you cancelled** on the Forecast screen no longer counts a charge that
  cancelling today could not avoid. A subscription on a notice period whose
  deadline for the next charge has passed was credited with that charge too;
  its figure, and so possibly its place in the list, now leaves it out. *Phase
  31.*

## [1.1.0] - 2026-10-01

### Added

- **API** — tags can be created (`POST /api/v1/tags`) and renamed
  (`PUT /api/v1/tags/{id}`), and a payment method's logo can be uploaded and
  removed (`POST`/`DELETE /api/v1/payment-methods/{id}/logo`). The web
  interface could already do all four. The OpenAPI document is now version
  1.1.0; nothing that existed in 1.0.0 changed. *Phase 30.*

## [1.0.0] - 2026-10-01

The first release: a self-hosted, multi-user tracker for subscriptions and
recurring bills. Requires PHP 8.4+ and PostgreSQL or MySQL/MariaDB.

### Added

- **Foundation** — sign-in, registration (closed by default), email
  verification, password reset with per-account and per-IP rate limiting, and a
  first-run setup wizard.
- **Households and roles** — instance admin, plus Owner/Admin, Editor,
  Contributor and Viewer per household; SHARED or ISOLATED data isolation
  applied by a central scoping layer. Invitations, email-change confirmation
  and account self-service; a Members & roles screen with the role matrix.
- **Subscriptions** — billing cycles, categories, tags, logos, saved views,
  quick-add, payment methods, and htmx filtering, sorting and pagination.
- **Money** — integer minor units throughout, multi-currency with exchange-rate
  refresh, trials and trial-conversion timing, price history, budgets with
  thresholds, and forecasting.
- **Notifications** — reminders run by a daily scheduler, with email and
  further channels (seven added in Phase 16) behind a `Notifier` interface;
  every user-URL channel goes through the SSRF-hardened HTTP client.
- **Security** — argon2id passwords, TOTP, passkeys (as a login method and a
  second factor), recovery codes, an audit log and session management.
- **API** — a versioned REST API with tokens, described in
  `openapi/openapi.yaml` and `docs/api.md`, both checked against the route
  table in CI; import/export with a tested round trip.
- **Calendar** — month view with a reading rail, chips, an open day, and an
  iCal feed built from `APP_URL`.
- **Dashboard** — Overview and Household views, customisable in place with drag
  and drop, per-view saved layouts and a reset; the year behind beside the year
  ahead.
- **Analytics** — spend over time, spend insights, and the forecast as a second
  tab; past spend reconstructed in one service and stopped at cancellation.
- **Profile and settings** — account and profile on one page; settings and
  notification preferences as tabbed cards.
- **i18n** — per-locale translation catalogues with a completeness check in CI.
- **Front end** — Vite build of Tailwind CSS 4, a small JS bundle with
  Chart.js loaded lazily, a Lucide icon sprite, and vendored Plus Jakarta Sans
  and JetBrains Mono. Nothing is loaded from a third-party host, enforced by
  `bin/console assets:offline-check`.
- **Theme** — the Renovo design: tokens, palettes, light and dark themes with
  contrast tested, and every screen (shell, dashboard, subscriptions,
  analytics, budgets, calendar, members, profile, settings and the signed-out
  screens) restyled to the prototype.
- **Operations** — Docker Compose for Postgres (default) and MySQL, a dev
  overlay with an asset watcher, `bin/dev-setup.sh`, Phinx migrations with
  explicit rollbacks, and sample data with a second household member and a
  year of history. The default port is 9090.

[Unreleased]: https://github.com/gwpreston16/Renovo/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/gwpreston16/Renovo/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/gwpreston16/Renovo/releases/tag/v1.0.0
