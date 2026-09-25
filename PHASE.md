# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 23 — analytics

The Analytics screen (`/stats`) rebuilt to the prototype: a year-to-date KPI row,
a 24-month chart that joins the past to the forecast, year over year, who pays
what, the most expensive subscriptions, a household-wide price history, and the
insights list. It introduces no new computation. It reads `SpendHistoryService`
(Phase 21), `ForecastService`, the year-over-year figures, the split service, the
notable ranking (Phase 12), price history and `SpendInsightService` (Phase 13).

## Depends on

- **Phase 21** — `SpendHistoryService` (reconstructed past spend).
- **Phase 20** — private rows, cancelled/paused exclusions, the price-change
  sources.
- **Phases 12, 13, 17** — the notable ranking, the breakdown donuts (category and
  payment method), `SpendInsightService`, `AnalyticsScreenService`.

## In scope this phase (build ONLY these)

### A. KPI row

| KPI | Binds to |
| --- | --- |
| **Spent this year** | January to today, reconstructed; note "+N% vs the same period last year" |
| **Average month** | this year's reconstructed spend ÷ elapsed months |
| **Next 12 months** | forecast total |
| **Price rises this year** | count of genuine rises recorded or scheduled this year (not trial or currency conversions), with their combined yearly effect per currency |

Every figure follows the per-currency rule.

### B. Twelve months back, twelve ahead

One chart: twelve reconstructed months, a **Today** marker, twelve forecast
months (hatched). Caption "Forecast includes trials converting and scheduled
price changes"; the reconstructed half carries the excluded-count note. The
dashboard's six-and-six chart is a window of this series — the same service, and
a test says so. Withheld with the currency named when a month cannot be combined.

### C. 2026 against 2025

Same-month bars for this year and last, from the existing year-over-year figures,
with the note "N subscriptions left out — no start date" when N > 0.

### D. Who pays what

Monthly share after splits, per member, as bars. Scoped exactly as the
dashboard's "Who pays": in ISOLATED mode only the viewer's own share ("Your
share"); private rows count only for their payer.

### E. Most expensive

The top five by monthly cost converted to base currency (Phase 12's ranking),
each shown in **its own currency**, rank, name and monthly figure. Rows whose
currency has no rate are left out and counted. One-off, lifetime, running trials,
paused and cancelled rows are not ranked (Phase 12's rules, plus Phase 20's
states).

### F. Price history

Every recorded change across the household, newest first: date (or
**Scheduled** badge for a future row), service, old → new, change (amount and %),
effect per year — each in the subscription's own currency. Trial conversions are
not listed as changes (Phase 13's guard); a currency conversion is listed as
"Converted" with no percentage, because the price did not change. Paged at 20
rows, with a link to each subscription.

### G. Insights

`SpendInsightService`'s rules rendered as the prototype's list: icon, title,
body naming the subscriptions and the arithmetic. Silence is still a result — no
card when no rule fires. Each links to the subscription, permission-checked. Not
called "AI".

### H. Kept from Phase 12 and 17, beneath the new sections

A **Breakdown** row with the category donut and the payment-method donut (both
degrade to per-currency figures), then the existing per-period costs
(day/week/month/year), the per-year-by-currency list and the cost-per-use ranking
with its usage form. Nothing the page carried is dropped. A link to the Forecast
page stays.

## Data-model changes

**None.**

## Explicitly out of scope

- New insight rules; a model-backed insight.
- A dashboard insight tile (still deferred from Phase 13).

## Decisions & assumptions (settled before build)

- **Most expensive shows the top five only**; Phase 12's "least expensive" line
  is dropped, as the prototype drops it.
- Both donuts are kept in a Breakdown row below the prototype's sections.
- A currency conversion appears in price history as "Converted", without a
  percentage.
- **The chart is 25 bars**: twelve reconstructed, this month split at today into
  already charged and still due, twelve forecast — the dashboard's shape, so its
  thirteen bars are a window of these.
- **This year against last** is same-month bars from the reconstruction (last
  January to yesterday) with the rest of this year from the forecast, hatched.
  The rolling twelve-months totals stay as one line beneath it.
- **Spent this year runs to yesterday**: today's charges are the forecast's, as
  on the chart.
- Price history includes paused and cancelled rows; a scheduled change on a
  cancelled row is left out of the table and the KPI.
- The two line charts (the year behind, the trajectory) and their script are
  removed; the 24-month chart replaces both.

## Status

- [ ] KPI row (reconstructed YTD, average, next 12, price rises)
- [ ] 24-month chart; dashboard window equality
- [ ] Year over year with excluded note
- [ ] Who pays what (scoped)
- [ ] Most expensive (top five, own currency, exclusions counted)
- [ ] Household price history (scheduled, conversions, paging)
- [ ] Insights list
- [ ] Breakdown row + retained Phase 12 sections
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

## Definition of done

Every figure binds to an existing service; the 24-month chart and the dashboard
chart agree; nothing reveals a private row or, in ISOLATED, another member's
spend; the per-currency rule holds on every KPI, chart and donut; nothing the old
page carried is lost; all palettes × themes, wide and narrow; gates green on both
engines. Then update `PHASE.md` to the next phase.

## Tests

- The dashboard's twelve months equal the corresponding twelve here.
- Price rises: trial conversions and currency conversions are not counted;
  scheduled rises are.
- Most expensive: order by converted monthly cost, display in own currency,
  unconvertible rows excluded and counted, trials/paused/cancelled absent.
- Price history: newest first; scheduled rows flagged; a Viewer sees it; a
  non-payer never sees a private row's history.
- Who pays: ISOLATED shows only the viewer's share.
- Per-currency: an unconvertible currency withholds combined KPIs and the chart,
  naming the currency.
- `AccessibilityTest` passes; the retained usage form still posts and returns
  here.