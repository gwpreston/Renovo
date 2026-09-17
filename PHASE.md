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

---

## Built — what actually landed

Every item above is done. Notes on the decisions that were open when the phase
started:

- **The metric row is four tiles, not one per currency.** Monthly and yearly
  spend are a tile each, listing their per-currency subtotals with the combined
  figure alongside only when every currency converts; a household in one
  currency sees one big number, and one in two sees the combined total over the
  subtotals it was computed from. When a currency has no rate the tile shows the
  subtotals and no total at all, which is the same refusal the rest of the
  application makes. The fourth tile — the design's virtual card — is the
  active-subscription count with the next charge and its date beneath it, taken
  from the forecast rather than from a payment date so that a trial converting
  on Friday counts as the next charge, because it is.

- **The chart is the Forecast page's own call.** `ForecastService::monthly()`
  with no member argument: the same household-wide figures `/forecast` renders,
  so the two screens cannot disagree about a month. `DashboardTest` asserts that
  by comparing the rendered payload against that call rather than against
  remembered numbers.

- **Nothing about money is decided in the browser.** The payload carries integer
  minor units for the bars and ICU-formatted strings for the tooltip; the
  y-axis ticks are *pinned* to values the server named and labelled, because the
  alternative — letting Chart.js invent tick values — would mean formatting
  currency in JavaScript, and a second money formatter is a second set of
  answers. The busiest month takes the amber that Phase 8 reserved for "money is
  about to move".

- **A month that cannot be combined means no chart.** A missing total draws as a
  short bar, which reads as a cheap month rather than an unknown one. The card
  names the currencies without a rate and points at the Forecast page, which
  shows those months per currency. Whether the horizon can be combined is asked
  once, of the union of every currency in it, by the same `StatsService::combine`
  everything else asks.

- **The bento is a property of the cards.** The grid is three columns and each
  card declares a `columnSpan()`; the chart asks for two and the usage widget
  for one, which is what puts them side by side. Everything else asks for three.
  So the design's split middle section survives being rearranged, and a narrow
  screen collapses to one column at the width the shell already changes shape
  at. Auto-placement is deliberately not dense: a dense grid reflows tiles past
  one another to fill a hole, and an order somebody chose is not something to
  improve on silently.

- **One near window, three consumers.** "Renewing soon" is the cancel-by view's
  fourteen days, and the renewals tile, the table's badge and the Expiring chip
  are all derived from one query for it. It counts *renewals* in that window
  rather than cancel-by deadlines: with a month's notice period a deadline can
  be behind you while the renewal is weeks away, and a tile headed "renewing
  soon" must count the renewals.

- **The table's identifier is the one the application already has.** The design
  carries a reference column; rather than invent a format for it, the column
  shows the subscription's own number — the one in the URL of every link to it.
  A card that renders nothing, meanwhile, no longer holds a gap open: the grid
  wrapper is emitted for every card in the list, so a household with no trials
  yet would otherwise have had an invisible tile between two visible ones.

- **The chips are the list's own filter and swap the rows alone.** They are real
  links, so they work with the bundle blocked; with htmx they replace the
  fragment and nothing else, because swapping the card would take the canvas
  with it. The chips live *inside* that fragment — a marker for "which view am I
  looking at" left outside it would still be pointing at the previous one.
  Export posts to the backup export that already exists, shown only to the role
  that may use it.

- **The usage widget shows one member's budget, chosen by a stated rule.** In
  SHARED isolation an Owner can see everybody's, and only their own belongs on
  their dashboard; somebody with several gets the overall before the
  per-category, the shorter period before the longer, the older before the
  newer. A member without one is invited to set one — but only if they may,
  because offering a Viewer a link the middleware will refuse is worse than
  offering nothing.

- **`DateFormatter` was extracted from the Twig extension.** The chart's axis
  labels are built before any template runs, and a label on the axis and a date
  in the table beneath it have to be the same string for the same day. It is the
  counterpart to `MoneyFormatter`, which services already share with the
  extension for exactly this reason.

- **Two layout faults were found by looking at the running application**, not in
  a test. The chart's table of figures — the screen-reader alternative and the
  no-script fallback — pushed the page 23px sideways on a phone even while
  "hidden", because a `<table>` cannot be laid out narrower than its own
  content; it is now a wrapper that shrinks and clips. The By category card,
  which predates this phase, overflowed the same way and is now wrapped like
  every other table.

- **An account that has already arranged its dashboard keeps its arrangement**
  and finds the three new tiles appended, which is what `DashboardLayoutService`
  has always done with a card it has not seen. Moving somebody's saved layout
  around to match a redesign would be the worse surprise, and the case values
  are unchanged because they are what `dashboard_cards.card_key` holds.
