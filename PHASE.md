# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 21 — the dashboard: Overview and Household

The prototype has two dashboards, and both are kept: **Overview** (variant A —
what the month and year cost, what is coming, where it goes) and **Household**
(variant B — how this month is going, who pays what, and the year against its
budget pace). One page, two views, a toggle between them. This phase replaces the
Phase 10 card set with the prototype's, on the Phase 18 theme inside the Phase 19
shell.

Every tile binds to a figure a service produces, and the money rules hold on
every one of them: minor units until ICU formats them, a combined total only when
every currency converts, and the missing currency named when one does not.

## Depends on

- **Phase 20** — Paused/Cancelled derivation, the cancel action (for "Cancel
  trial"), private-row scoping, household and member budgets.
- **Phases 10–13** — `SpendChartService`, `CategoryBreakdownService`,
  `ForecastService`, `SpendInsightService` (price-rise rule),
  `DashboardLayoutService` and `dashboard_cards`.
- **Phase 2** — the year-over-year reconstruction, from which past-month spend is
  extracted (section C).

## In scope this phase (build ONLY these)

### A. The view toggle and the greeting

- A segmented control, **Overview / Household**, at the top of the dashboard.
  The choice is saved per account (`users.dashboard_view`) and the dashboard
  opens on it next time. The existing "default landing view" preference is
  unchanged — it chooses *whether* you land on the dashboard; this chooses
  *which* dashboard.
- Each view has its own card list, reorder and hide, keyed by view in
  `dashboard_cards`, so hiding a card on Overview leaves Household alone.
- Greeting: "Welcome back, {first name}" and "Here's where {household} stands on
  {date}" (ICU date). **No time-of-day greeting**: dates are UTC (Phase 3's known
  limitation) and "Good afternoon" at 7am would be wrong for anyone not in UTC.

### B. Overview (variant A)

| Card | Binds to |
| --- | --- |
| **Monthly spend** KPI | recurring monthly total, per-currency rule; note "N% of {budget}" only when the viewer has a monthly overall budget, else no note |
| **Yearly run-rate** KPI | yearly normalised total at today's prices ("at today's prices" is the honest caption; the forecast lives elsewhere) |
| **Due next 7 days** KPI | count, and the amount per currency / combined |
| **Active** KPI | active count, with "N trials · N paused" beneath |
| **Monthly spend chart** | six months back (reconstructed, section C) and six ahead (forecast, hatched), a line at the monthly overall budget when one exists; the busiest month picked out |
| **Where it goes** donut | `CategoryBreakdownService`, flat categories, monthly equivalent in base currency; degrades to per-currency figures, no donut, when a currency cannot convert |
| **Coming up** | charges and trial conversions in the next 30 days from the forecast — date tile, name, owner initials, amount in its own currency and ≈ base; links to all subscriptions |
| **Budgets** | up to four of the viewer's monthly budgets: charged so far against projected, note (left / over / past warning / "projected over if trials convert") |
| **Free trials** | running trials: end date, days left, who started it, what it converts to (own currency, ≈ base); **Cancel trial** (Phase 20 cancel) drawn only where `mayWriteRow` allows; empty state "No trials running" |
| **Price change** banner | the most imminent scheduled rise from the Phase 13 price-rise rule: old → new, monthly and yearly difference, link to price history; absent when there is none — never a hardcoded subscription |

The prototype's **"Keep it"** button on trial cards is not built: keeping a
trial is what happens when you do nothing, so a button for it has nothing to do.

### C. Past spend is reconstructed, and says so

Renovo has no ledger. The prototype's "what left the account each month" is
therefore **reconstructed** from start dates, cycles and price history — exactly
how year-over-year already works. That logic is extracted from the Phase 2
year-over-year code into one `SpendHistoryService`, so the dashboard, Analytics
(Phase 23) and Budgets (Phase 24) share it and year-over-year keeps its figures.

- The past series is captioned **"Reconstructed from start dates and price
  history"**, and the card states how many subscriptions were left out for having
  no start date — the same note year-over-year gives.
- Cancelled rows count up to `cancelled_at`; paused rows are treated as
  year-over-year already treats inactive rows (no `paused_at` exists).
- The six-ahead half is `ForecastService`, so the chart's future matches the
  Forecast page month for month.

### D. Household (variant B)

| Card | Binds to |
| --- | --- |
| **{Month} so far** hero | "£X **already charged** of £Y due this month" — charges in this month whose date has passed, against all charges due in it; a charged / still-due bar with a marker at the monthly household budget if one exists. Wording is deliberately "charged", not "paid": nothing confirms a payment |
| Hero figures | **Year to date** (reconstructed) with % against the same period last year; **Next 12 months** (forecast); **Trials converting** (+monthly cost from N trials) |
| **Next 30 days** timeline | charges placed on a 30-day axis with ticks (Today, +7, +14, +21, +30), count and total |
| **Who pays** | each member's monthly share after splits, subscription count and % of household spend, from the split service (custom shares included) |
| **Spent this year vs budget pace** | cumulative reconstructed spend January to now against an even pace of the household's yearly budget; shown only when a household yearly (or monthly ×12) budget exists |
| **By category** | proportion bars, same service as the donut |

**Who pays under ISOLATED mode** shows only what the viewer can see: their own
share (and nothing that would reveal another member's spend). The card retitles
itself "Your share" there rather than showing a household it cannot see. Private
rows (Phase 20) count only for their payer.

### E. What happens to the Phase 10 cards

- Monthly spend, yearly spend, upcoming renewals, the chart and the usage widget
  are **replaced** by the Overview cards above; their card keys map to the new
  ones where the meaning survives (monthly, yearly, chart, budgets).
- The **recent subscriptions table** with its filter chips stays available as an
  optional Overview card, **hidden by default**.
- **Saved layouts**: see the open decision below.

## Data-model changes

1. `add_dashboard_view_to_users` — `dashboard_view` string(10), nullable
   (null = `overview`).
2. `add_view_to_dashboard_cards` — `view` string(10) not null default
   `overview`; unique key becomes `(user_id, view, card_key)`.

Both with explicit `down()`, verified on both engines.

## Explicitly out of scope

- The Subscriptions, Analytics and Budgets pages (their own phases), though they
  reuse `SpendHistoryService`.
- A time-of-day greeting (needs per-user time zones).
- An in-app notification inbox (decision 6).

## Decisions & assumptions (confirmed 2026-09-24)

- **Saved layouts — reset (a).** A migration of its own clears every saved
  layout once, so everyone starts from the new defaults; its `down()` is a
  documented no-op (a deletion cannot be undone). Noted in the release notes.
- **The subscriptions table is reinstated** as an optional Overview card,
  hidden by default, under its old key `recent`. (It was retired in 530eb3a; this
  phase brings it back as an opt-in card rather than dropping the line.)
- **Cancelled rows stop at `cancelled_at` everywhere**, year-over-year
  included. The extraction lands first as a pure move with the old figures
  pinned; the cutoff follows as its own change, and changes YoY only for rows
  that carry a `cancelled_at`.
- **Past and future split at today.** The reconstruction covers charges
  *before* today; the forecast covers today onwards. The current month is one
  bar in two parts — already charged, still due — and the Household hero reads
  the same split, so no charge is counted twice.
- **First name** is the display name up to its first space (there is no
  first-name field).
- The view toggle is a web preference (CSRF-protected POST); no API route.
- "Keep it" on trials is not built.
- "Already charged", not "paid".
- Who pays becomes "Your share" in ISOLATED.
- The budget line and pace appear only when a relevant budget exists — never an
  invented limit.
- Built on `phase-18-theme`, after Phases 18–20.

## Status

- [ ] Migrations 1–2
- [ ] View toggle, saved per account; per-view card lists
- [ ] `SpendHistoryService` extracted from year-over-year (YoY figures unchanged)
- [ ] Overview: four KPIs, spend chart (reconstructed + forecast + budget line),
      donut with degrade, coming up, budgets, free trials with Cancel trial,
      price-change banner
- [ ] Household: month-so-far hero, hero figures, 30-day timeline, who pays
      (scoped), year vs budget pace, by category
- [ ] Phase 10 cards mapped/retired; subscriptions table optional
- [ ] Narrow layout: one column, charts legible at 390px
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

## Definition of done

Both views render real figures only, in all palettes × themes, wide and narrow;
the chart's future half equals the Forecast page and its past half equals
`SpendHistoryService`; every money figure follows the per-currency rule including
the withheld total; nothing on either view reveals a private row or, in ISOLATED,
another member's spend; the toggle and per-view layouts persist; the gates pass
on both engines. Then update `PHASE.md` to the next phase.

## Tests

- Year-over-year figures are identical before and after the extraction.
- The chart's forecast months equal `ForecastService::monthly()`; its past months
  equal `SpendHistoryService` and carry the excluded count.
- Per-currency: with an unconvertible currency the KPIs show subtotals and no
  combined figure, and the chart and donut are withheld with the currency named.
- "Already charged" counts only charges dated before today in the current month.
- Who pays: ISOLATED shows only the viewer's own share; a private row is absent
  for non-payers; custom split weights are honoured.
- Cancel trial is drawn only where the viewer may write the row, and a forged
  POST from one who may not is refused.
- The price banner is absent with no scheduled rise and names the right one with
  several.
- The view choice persists; hiding a card in one view does not affect the other.
- `AccessibilityTest` passes for both views; one primary action per screen.