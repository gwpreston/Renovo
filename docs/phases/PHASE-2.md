# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 2 — Money: currency, trials, price history, budgets, forecasting.**

Goal: turn tracking into budgeting and financial insight. Everything here is
data + in-UI surfacing; actual outbound alerts are wired in Phase 3.

## Depends on
Phase 1 — subscription model, central scoping layer, notice-period field, shared
HTTP client, first-run wizard.

## In scope this phase (build ONLY these)
- Exchange rates: pluggable provider behind an interface. Free default
  (Frankfurter or ECB feed); exchangerate.host optional; **Fixer optional, never
  default**. Fetch via the shared HTTP client. Cache rates; combined
  multi-currency totals; degrade to per-currency subtotals if unavailable.
- Extend the first-run wizard: choose rate provider + enter a key if needed.
- Price-change history: immutable rows with effective dates (never overwrite);
  current price = latest row; support scheduled future price changes. Price-trend
  view per subscription.
- Free-trial tracking: trial end date + converts-to price/cycle; surface upcoming
  conversions on the dashboard.
- Budgets: overall and per-category; monthly and annual amounts; scoped to the
  isolation mode. Trigger is **projected spend**. Show per-budget progress and
  over-budget indicators in the UI (firing alerts is Phase 3). Optional warn
  threshold (e.g. 90%).
- 12-month forecast: renewals + trial conversions + scheduled price changes.
- Stats: cost per day/week/month/year; year-over-year comparison.
- Usage / "worth it?" signal: usage count or manual rating; surface high-cost/
  low-use subscriptions.
- Cancellation dashboard: list by cancel-by date (from notice-period), sortable
  by urgency.
- Shared-cost splitting: equal or custom shares across household members; always
  visible to participants regardless of isolation; each member's budget counts
  their share.
- Bulk actions: multi-select change of category, tag, member/payer, currency
  (permission-scoped).

## Explicitly OUT of scope until a later phase (leave seams, do NOT stub)
- Outbound notifications / scheduler (this phase only shows state in the UI) → **Phase 3**
- JSON API / OpenAPI → **Phase 5**
- Advanced auth (passkeys/OIDC/audit) → **Phase 4**
- i18n / calendar view / metrics → **Phase 6**

## Status
- [x] Exchange-rate providers + interface + cache; combined totals + graceful degrade
- [x] Wizard extended (rate provider + key)
- [x] Price history (immutable) + scheduled changes + trend view
- [x] Trials + upcoming-conversion surfacing
- [x] Budgets (projected) + UI progress/over-budget indicators
- [x] 12-month forecast
- [x] Stats: per-period costs + YoY
- [x] Usage scoring
- [x] Cancellation dashboard
- [x] Shared-cost splitting (respects isolation visibility)
- [x] Bulk actions
- [x] Tests: trial timing, price-history current-price + scheduled changes,
      budget projection per scope/isolation, forecast, splits, rate conversion +
      degrade — all passing (601 tests, green on PostgreSQL and MySQL)

## Decisions & deviations made during the build

**Exchange rates**
- Default provider is **Frankfurter** — free, no account, ECB daily rates. A new
  instance converts currencies without its operator signing up to anything.
  exchangerate.host and Fixer are offered; Fixer is never first.
- Rates stored as integers scaled by **10^8**, against the instance's base
  currency only. Other pairs are cross-rated through the base, so a refresh is
  one row per currency rather than one per pair.
- Cache TTL **12 hours**; failed refreshes are not retried for **1 hour**, so a
  provider outage cannot make every page view a failing network call. Both are
  env-configurable.
- **API key: the environment wins.** `EXCHANGE_RATE_API_KEY` takes precedence
  over a key entered in the wizard or settings page, per the standing rule that
  secrets come from env vars.
- Provider endpoints are constants in their own classes. **No URL is ever read
  from the database**, so this phase still accepts no user-supplied URL and the
  SSRF hardening of the shared client remains a clean seam for the phase that
  first does (webhooks, Phase 3).

**Price history**
- Append-only. `subscriptions.price_minor` is kept as a denormalised "current
  price" because the list view sorts, filters and totals on it; every write that
  changes which row is current updates it in the same transaction.
- The current row is the greatest `effective_from`, ties broken by greatest id —
  deliberately *not* MAX(id), since a change scheduled for next month is
  recorded before a correction applied today.

**Trials**
- The trial's **last day is the day the conversion charge falls**. Conversion is
  dated to that day even if it is applied weeks later.

**Splits**
- Integer **weights** are stored; amounts are derived from the current price each
  time, allocated by largest remainder so the shares always sum exactly.
- Participant visibility is applied by a **read-only** widening in the scoping
  layer (`readVisibilityPredicate`, composed into `scopedWhere` alone). Writes
  use the unwidened predicate, and queries that feed writes use `writableWhere`.
- The widening has to reach **everything that decorates a widened row**, not just
  the query that finds it. Tags are loaded by a second query and price history
  lives in its own table; both were narrower than the row they describe, so a
  participant saw an untagged subscription whose price had, it claimed, never
  been recorded. Both now use the read predicate.
- Conversely, a service that reads with `find()` and then writes has already lost
  the distinction, because `find()` is widened. `SubscriptionRepository::findForWrite()`
  exists for those callers. **Scheduling a price change was reachable**: the route
  requires `ManagePrices`, which any Editor holds, and `PriceHistoryService::schedule()`
  gated on `find()` and then appended to a *different* table — so it never touched
  the subscription's write predicate at all. An Editor named on a split could
  schedule a rise on another member's subscription in ISOLATED mode, and the
  catch-up promoted it to the current price when its date arrived. It now gates on
  `findForWrite()`.
- Widening the price history is not only a display change: `ForecastService` prices
  each charge from the history row effective on that date and falls back to the
  denormalised current price when it can see none, so a participant's forecast and
  budget had previously quoted every future month at today's price and hidden any
  scheduled rise from the person paying half of it.
- Bulk actions changed behaviour with this: the two loops that fetch each row
  before writing now use `findForWrite()`, so a participant's selection of a split
  row is skipped like any other unwritable row instead of aborting the whole batch.

**Budgets**
- Owned by a member, measuring **that member's share**. Visible household-wide in
  SHARED, own-only in ISOLATED.
- Projection comes from `ForecastService`, not a run-rate, so a budget cannot
  disagree with the forecast shown beneath it.
- Periods are **rolling windows from today**, not calendar periods — there is no
  payment ledger, so a calendar month would have to omit what was already
  charged in it.

**Year over year**
- **Reconstructed** from start dates, cycles and price history; no ledger was
  built. A subscription with no start date contributes to neither year, and the
  page says how many were excluded.

**Bulk currency change**
- **Converts** at today's rate and records a price-history row, and **refuses**
  when no rate is available. Re-labelling £9.99 as €9.99 would be a silent 20%
  price change.

**Catch-up ordering**
- One entry point (`CatchUpService`), ordered: apply due price changes → convert
  ended trials → advance overdue payment dates. Advancing first would roll a
  payment at a stale price. It is a no-op for any scope that cannot write, so a
  Viewer's GET never writes. This is also the seam Phase 3's scheduler calls —
  no stub was needed.

**Known limitations, stated rather than fixed**
- `CatchUpService` runs on GET, and its reads happen outside the transaction that
  writes. Two simultaneous page loads can both see the same unconverted trial and
  both append a conversion row. The resulting price is identical either way, so no
  money is wrong — the trend shows a duplicate step. Concurrency hardening is not
  in this phase's scope; the scheduler seam in Phase 3 is where a single writer
  belongs.

**Portability fixes found by running against both engines**
- MySQL's native prepared statements reject a named placeholder used twice; the
  one query that did so now binds the value under two names.
- MySQL's affected-row count is "rows changed", PostgreSQL's is "rows matched",
  so a no-op UPDATE reported zero and raised a spurious scope violation. The two
  places that inferred existence from that count now ask explicitly, via a new
  `Platform::reportsMatchedRowsOnUpdate()`. This was a latent Phase 1 bug that
  Phase 2's repeated writes exposed.

## Definition of done
App runs, all Phase 2 tests green, CI green, no Phase 3+ features stubbed. Then
copy PHASE-3.md over PHASE.md and commit.