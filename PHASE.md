# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 6 — i18n, calendar & UX polish, observability & ops.**

Goal: localise, polish, and make the app operable and observable. This is the
final planned phase.

## Depends on
All previous phases — there must be features to localise, monitor, and show in a
calendar.

## In scope this phase (build ONLY these)
- Internationalisation: route all user-facing strings through a translation
  layer, keyed, with `en` as the canonical base (server + any JS strings).
- Translation-completeness script (runnable in CI): diff every locale against the
  base, report missing/extra keys, non-zero exit on drift. Document adding a
  locale. Extend CI to run it.
- Calendar VIEW of upcoming renewals and trial conversions; configurable week/day
  start (Sunday vs Monday).
- Saved views / filters: persist named filter sets (category, payer/member,
  currency, active/inactive, tag) per user. ("Payer" == the household-member
  field, not a separate concept.)
- List/table density options (comfortable/compact).
- Dashboard customisation: reorder/hide cards, choose the default landing view.
- Quick-add modal + keyboard shortcuts for common actions.
- Logo/icon fetching optimisation: server-side fetch **through the hardened SSRF
  client**, with caching + deduplication by domain/hash + minimised/batched
  lookups.
- Accessibility pass: ARIA labels, visible focus states, adequate contrast in
  both themes, full keyboard navigability, a screen-reader smoke pass.
- Health endpoint: liveness + readiness (DB connectivity, scheduler last-run,
  migration status).
- Prometheus `/metrics`: app + business metrics (users, active subscriptions,
  reminders sent, notification failures, rate-refresh status, HTTP basics).
- Read-only demo / kiosk mode: instance setting exposing a non-mutating,
  pre-seeded view with no leakage of real accounts.

## Explicitly OUT of scope (not in v1 at all)
- Bank/transaction sync (Plaid/GoCardless/Firefly) — future optional module.
- Extra notification channels beyond the Phase 3 four — add later behind the
  existing interface (Apprise bridge recommended first).

## Status
- [x] i18n layer + `en` base; strings routed through the translator
- [x] Locale-completeness script + CI check
- [x] Calendar view + configurable week start
- [x] Saved views/filters (per user)
- [x] Density options
- [x] Dashboard customisation (reorder/hide, default landing)
- [x] Quick-add modal + keyboard shortcuts
- [x] Logo fetch via SSRF client + cache/dedup
- [x] Accessibility pass (ARIA, focus, contrast, keyboard, screen-reader smoke)
- [x] Health endpoint
- [x] Prometheus /metrics
- [x] Demo/kiosk mode
- [x] Tests: locale script fails on incomplete locale, calendar week start,
      saved views persist, logo cache/dedup avoids duplicate fetches, health
      reflects failures, demo mode blocks mutations — all passing

## Decisions & deviations made during the build

**Translation library: none.** `App\I18n\Translator` is about 120 lines over
flat `key => message` PHP files in `translations/`, with ICU MessageFormat
handled by ext-intl, which this application already required. The reason for
writing it rather than taking `symfony/translation` is not weight: it is that
the completeness check PHASE.md asks for is a set difference over the keys of
two arrays, and that stays a five-line method only while the catalogue is a
flat array. What ext-intl is doing is the part that would have been wrong by
hand — plural rules belong to a language, and a layer that decided between
singular and plural with `$n === 1` is correct in exactly the languages that do
not need it. Fallback is `fr_CA` → `fr` → `en`, so a regional catalogue holds
only what it says differently and only `en` has to be complete.

**Strict mode is the extractor we did not write.** The completeness script
compares locales with the base and cannot see the other failure: a key used by
a template and present in no catalogue. Rather than build a source extractor —
fragile against any key that is composed rather than written out, and therefore
a CI step people learn to ignore — the translator throws under `APP_ENV=test`.
The page-rendering tests then cover it for real, and they cover two more
failures a key-diff cannot see: a message with arguments called without any
(which would print its own ICU source to the page) and a malformed message.

**Flashes and validation errors carry keys, not sentences.** A flash is queued
by one request and rendered by the next, which may be in another language, so
what is stored is `['type', 'key', 'parameters']` and the partial resolves it.
`ValidationException` carries `ValidationError` objects for the same reason and
one more: the same service answers a browser and an API client, and each should
hear it in their own language. The Twig side resolves through `error_message()`
and the API side through the error handler, so no service needed a translator.

**Notifier and provider names are left untranslated.** "Gotify", "Slack" and
"Frankfurter (ECB)" are product names. Their *field labels* and *descriptions*
are interface copy and are keyed. `NotifierException` messages — which end up
stored in `notification_channels.last_error` — stay English: they are
diagnostics from a channel about its own failure, they are shown through a
`flash.raw` passthrough, and translating a sentence a remote service handed us
is not possible anyway.

**Dates go through ICU too.** Twig's `date` filter formats month and day names
with PHP's English, whatever language the page is in; `|local_date('d MMM y')`
hands the job to `IntlDateFormatter` with the request's locale. The patterns in
the templates are ICU skeletons, so the *order* of the parts is the locale's
decision as well as the words.

**Two writers set the locale, in this order.** `LocaleMiddleware` runs globally
and negotiates `Accept-Language`, which is the only thing available on the
sign-in page. `AuthenticationMiddleware` then overwrites it with the user's
stored preference once the account is loaded — it cannot run globally, because
the user is not known until a route has been chosen. The API's middleware does
the same from the token's owner, and the scheduler sets it per recipient so
each digest is built in the language its reader chose.

**The calendar reads the forecast rather than walking dates again.** Same rule
as the budgets and the twelve-month view, and it is what makes the three agree:
a scheduled price rise shows the amount that will be taken, not today's. It
pages no further back than the current month, because this application tracks
what is due rather than keeping a ledger of what was paid — a calendar of last
year would be an invention. The grid itself is a pure function of (year, month,
week start) in `CalendarGrid`, so the preference is unit-tested rather than
inferred from a rendered page.

**Week start is a preference, not a locale lookup.** ICU has an answer for
every locale and it is the wrong answer often enough to matter: `en_GB` and
`en_US` disagree, and plenty of people read a Monday-first calendar in an
American locale because that is how their working week runs.

**Preferences are columns; the dashboard layout is a table.** Locale, week
start, density and landing view are scalars with fixed value sets, read on
every page render, and they sit on `users` beside the theme column that has
been there since Phase 1. Which dashboard cards are shown and in what order is
a list, so it gets `dashboard_cards`. Neither is JSON: JSON operators are the
clearest place PostgreSQL and MySQL diverge, and this application runs on both.
A user with no rows in `dashboard_cards` gets the default layout, and a card
added by a later version appears in its default place rather than vanishing for
everybody who had ever saved a layout.

**A saved view stores the query string and re-parses it.** `SubscriptionFilter`
already validates every part of a filter against allow-lists, because it has to
for a URL somebody pasted. Running the saved value back through it on save
means what lands in the database is a filter this application would have
produced, and a tampered row can no more reach the SQL than a tampered link.

**The quick-add dialog loads the real form.** `/subscriptions/new` renders
without the surrounding page when htmx asks for it, so there is one definition
of what a subscription needs rather than a short version that drifts from the
long one. Native `<dialog>`, so focus trapping and Escape are the browser's
job. Every shortcut is also a link or a button: with JavaScript off the
application loses convenience and no function.

**Logo fetching needed a URL, so subscriptions gained `website_url`.** Guessing
a domain from a name would be wrong often and wrong invisibly, and asking a
third-party icon service would mean sending a household's list of subscriptions
to a stranger. So the field is asked for, it is useful on its own (it is the
link you want when you have decided to cancel something), and the fetch asks
that domain for `/apple-touch-icon.png` and three other conventional paths
through the guarded client, https only.

**The logo cache dedups requests, not files.** One row per domain with a
cached original under `var/logo-cache`, including a negative entry for a domain
with no icon — the half that is easy to omit and expensive to omit, since
without it a domain with no favicon is asked again on every save. Each
subscription still gets its own copy through `LogoStorage`, deliberately:
sharing one file between subscriptions would make `delete()` a refcounting
problem, where removing one subscription's logo blanks another's.

**Metrics: hand-rendered, and off unless configured.** The Prometheus
exposition format is a HELP line, a TYPE line and `name{labels} value`; a
client library would have added a registry, a storage adapter and a locking
strategy to produce it. `/metrics` does not exist until `METRICS_TOKEN` is set,
and an instance administrator can read it in a browser; anybody else gets 404
rather than 401, because confirming that the endpoint exists is itself an
answer. Turning it on is also what adds `MetricsMiddleware` to the stack, so an
instance nobody scrapes writes nothing.

**Liveness touches nothing; readiness touches everything.** An orchestrator
restarts what fails a liveness probe, so a liveness check that queried the
database would turn a brief outage into a restart loop. `/readyz` is the one
that asks about the database, the schema and the scheduler — and the scheduler
check needed a writer, so `reminders:run` now records when it finished.
Migration status is read through `MigrationRepository` rather than by a service
reaching into `phinxlog`, and all three endpoints are exempt from
`SetupGuardMiddleware`: a health check that redirects to the first-run wizard
is a container that never becomes ready.

**Demo mode is a global middleware in front of routing.** Not inside the
authenticated group and not folded into the CSRF middleware — both would have
left `/api/v1` writable, since the API authenticates by bearer token outside
that group and is exempt from CSRF for exactly that reason. Sign-in and
sign-out stay open, and so does the instance settings form for an instance
administrator, or the switch could not be turned off again without database
access. Read isolation is not special-cased at all: the demo account is an
ordinary member of its own household, so the scoping layer already answers it —
and there is a test that says so, because "no leakage of real accounts" is a
requirement and a requirement with no test is a hope.

**Demo data is written through the services.** `demo:seed` creates its
subscriptions the way a person would, so the figures on the demo dashboard are
computed rather than typed in. The names are invented: a demonstration that
lists real services reads as an endorsement, and it is the numbers that are
being shown.

**Accessibility is asserted, not claimed.** `tests/Functional/AccessibilityTest`
fetches all nineteen pages and asserts one `<h1>`, a `main` landmark, a `lang`
attribute, a skip link, a name on every control, `scope` on every `<th>` and an
`alt` on every image. Contrast was measured rather than eyeballed: every
foreground/background pair in both themes is at or above 4.5:1 (the lowest is
the accent on the light page background, at 5.34:1). Reduced-motion and
forced-colours media queries are honoured. What is *not* covered is a live
screen-reader run — no assistive technology was available in the build
environment — so reading order and whether each control's name makes sense out
of context remain a manual check for a sighted-plus-screen-reader pass before a
release.

**The importer's preview is a dry run, and dry runs write nothing.** The same
`validate()` serves the real write and the preview that tells a user which of
four hundred rows would fail, so the flag that already stopped it inventing
tags now stops it fetching logos too. Left alone it would have been four
hundred outbound requests during a page render, for rows nobody had agreed to
import — latent only because the importer has no website column yet, which is
exactly the kind of fuse that gets lit by an innocuous later change. Covered by
a test that counts requests.

**The request counter sits outside the error middleware.** Inside it, a 404 or
a 403 arrives as an exception rather than a response and is recorded as a 500 —
every handled error in the wrong bucket, in the direction that matters most on
a dashboard. Covered by a test that asks for a missing page and reads the
exposition back.

**Demo mode guards HTTP and deliberately not the scheduler.** It is middleware,
so `reminders:run` still runs on a demonstration instance and still sends to
whatever channels are configured. That is the right shape: the switch makes the
*instance* read-only to its visitors, and an operator who does not want a demo
emailing anybody configures no channels on the demo account — which is the
state a freshly seeded one is in. Blocking the scheduler would also mean a demo
whose dashboard slowly drifts out of date as payment dates pass.

**A latent portability bug surfaced on the way.** `AbstractRepository` bound
one named parameter and used it once per searched column, which PostgreSQL
rewrites happily and MySQL — with native prepared statements — refuses outright
("Invalid parameter number"). It has been there since Phase 1 and had never
fired, because no test had searched the subscriptions list on MySQL. The saved
views test does, which is how it came out. Fixed by binding a placeholder per
column, and covered on both engines from now on.

### Deferred, with reasons

- **No second locale ships.** The machinery is there and `i18n:check` reports
  "nothing to compare" until somebody adds one; translating 860 strings is a
  translator's job, not a build step, and a machine-translated `fr` would be
  worse than none.
- **Stored notes stay in the language they were written in.** The price-history
  note a currency conversion writes ("Converted from GBP at 1.17") and the one
  a trial conversion writes are rows, not interface copy. Translating them at
  render would mean parsing them back; translating at write means storing one
  member's language for everybody. Left as they are, and noted here.
- **Audit-log detail is not translated.** The context column is machine data —
  `reason: bad_password` — and the action labels beside it are keyed. Inventing
  a key per context shape would be a translation surface that changes whenever
  a service adds a field.
- **The calendar does not page backwards.** See above: there is no ledger to
  page back into.
- **No per-path HTTP metrics.** A counter per URL grows without limit the first
  time somebody scans the instance for admin panels. Status classes answer the
  question the numbers are for.

## Definition of done
App runs, all Phase 6 tests green, CI green (incl. locale check), demo mode
blocks writes. v1 feature set complete — record any deferred items and remaining
polish here for future work.

**Met.** The gates pass on PostgreSQL and MySQL: PHP_CodeSniffer clean, PHPStan
level 6 clean, `php bin/console i18n:check` clean, and the full suite green.
Deferred items are listed above; the polish worth doing next, in rough order of
value:

1. **A real screen-reader pass.** The structural checks are automated; reading
   order and out-of-context control names are not, and cannot be from here.
2. **A first translated locale**, which is also the only real test of whether
   the catalogue's keys make sense to somebody who cannot see the page.
3. **OIDC/SSO**, left out of Phase 4 and still the largest missing piece for
   anybody running this beside other self-hosted services.
4. **An Apprise bridge**, which would add every channel it supports behind the
   existing `Notifier` interface without another class per service.