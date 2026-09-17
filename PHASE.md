# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 12 — analytics and insights

The analytics screen restyles what is today the Statistics page, drawing also on
the Forecast and year-over-year figures, into the design's KPI row, spending
trajectory, category donut and notable-subscriptions card. No new computation is
introduced here — the numbers exist; this is how they are shown. (The design's
"AI Insight" hero is a separate concern and is Phase 13.)

## The KPI row

**Monthly spend**, **annual spend**, and **active subscriptions**, from the
figures the Statistics page already produces. Money keeps the per-currency rule:
each KPI is a real figure with a combined total only when every currency in it
converts, and the missing currency named when one does not.

## Spending trajectory

The design's orange gradient bars are the twelve-month view, same source as the
dashboard chart, so the two agree by construction. Each renewal falls in its own
month; scheduled changes and conversions apply from their own dates, so a bar does
not move when the change eventually lands. Reuses the Phase 10 chart (Chart.js, from Phase 7)
rather than adding a second.

## Category donut

The category breakdown as a donut, with a centre label. The label is the total
that donut represents — and here the per-currency rule bites hardest: a donut
implies one whole, so when the categories span currencies that cannot all be
converted to one base, the screen shows the per-currency figures rather than a
donut whose centre would be a number that does not exist. Degrading to the honest
view is the behaviour, not an edge case to paper over.

## Year over year and notable subscriptions

The comparative element uses the existing year-over-year figures, which are
reconstructed from start dates, cycles and recorded price history — there is no
payment ledger, so a subscription with no start date contributes nothing rather
than an invented amount, and the page says how many were excluded for that reason.
The design's "notable" card (highest and lowest cost) is a straight read of the
subscription set, each shown in its own currency.

## Done when

Every KPI, bar, donut and notable figure binds to an existing service; the
trajectory matches the dashboard chart; the donut degrades to per-currency figures
when it must rather than showing a false whole; the excluded-count note is
present; both themes render; narrow viewports reflow; new strings are catalogued;
the quality gates and `i18n:check` pass.