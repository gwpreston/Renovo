# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 10 — the dashboard

The landing screen, as a bento grid: a row of metric cards, a split middle
section with a chart and a usage widget, and a table of recent activity beneath.
Every tile binds to a figure a service already produces. Where the design named a
figure the application does not have, Phase 8 already decided its fate; this phase
builds only the tiles that bind to something real.

## The metric cards

The design's four-card row becomes cards Renovo can actually fill:

- **Monthly spend** and **yearly spend**, each shown as **per-currency
  subtotals** with a combined total alongside only when every currency converts —
  the same rule the rest of the application follows. A single big number is the
  design's instinct; a single big number that silently omits a currency is a
  wrong number, so a card may show two lines, and that is correct rather than a
  compromise.
- **Upcoming renewals** — the count in the near window, the same figure the
  cancel-by view is built from.
- **A fourth card that is not a fake card.** The design's slot here was the
  virtual card; in its place goes something true — active-subscription count, or
  the next charge and its date. Not a masked PAN.

One-off and lifetime entries stay out of the recurring figures and are shown
separately if at all, exactly as elsewhere: the headline is a recurring total and
must keep meaning that.

## Cash flow chart

The design's income-vs-expenses chart becomes a **twelve-month spend chart drawn
from the existing forecast** — each renewal in the month it actually falls, with
scheduled price changes and trial conversions applied from their own dates. There
is no income series, so the chart is spend over time, with the current or peak
month picked out in amber. It reads the forecast the Forecast page and the
budgets read, so the dashboard and those pages cannot disagree.

The chart is **Chart.js**, the charting library chosen in Phase 8 and bundled by
the Phase 7 pipeline — nothing is fetched at runtime. It is fed
integers-as-minor-units converted for display at the boundary; it never receives
a float currency value.

## The usage widget

The design's "Subscription Usage — $1200 from $299 limit" becomes the real thing
it was gesturing at: **a budget against its projected spend**, taken from the same
forecast, for the signed-in member's own share. Beneath it, the category
distribution bars the design shows, drawn from the category breakdown the
Statistics page already computes. A member with no budget set sees a prompt to set
one rather than an invented limit.

## Recent / active subscriptions table

The bottom table lists subscriptions with the columns the design asks for, mapped
to real fields: an identifier, the app (its cached logo and name), the amount in
its own currency, the billing period, and a **status badge computed from real
state** — active, renewing soon (inside the near window), or trial (before its
conversion date). The filter chips (All, Active, Expiring) reuse the list's own
filter mechanism, and Export reuses the existing export rather than a new path.

The table is scoped by the same repository layer as the list: on an ISOLATED
instance it shows the member's own subscriptions plus any they help pay for, and a
Viewer sees no mutating controls because the middleware, not the template, is what
would refuse them.

## Rearrangeable cards

Renovo already lets an account reorder dashboard cards and untick ones it does not
want. The new tiles join that mechanism rather than being fixed, and a tile added
by this phase appears in its default place for existing accounts rather than
going missing.

## Done when

Every tile shows a real figure or is not present; the chart matches the Forecast
page for the same data; per-currency behaviour is correct including the withheld
combined total; badges reflect real state; the grid reflows to one column on
narrow screens; both themes render; new strings are in the catalogue; the quality
gates and `i18n:check` pass.