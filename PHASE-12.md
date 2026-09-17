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
## Decisions and assumptions

- **One chart payload, not two copies of one.** "Reuses the Phase 10 chart" is
  not satisfied by a second `chart()` method, so `DashboardService`'s came out
  into `SpendChartService` and both screens call it. A test compares the payload
  the dashboard hands its canvas with the one this screen hands its own, month
  for month — the claim asserted rather than described. The category logic came
  out the same way into `CategoryBreakdownService`: the my-subscriptions widget
  draws it as bars and this screen draws it as a donut, and two pictures of one
  breakdown cannot disagree.

- **The donut is drawn or it is not drawn.** A donut implies one whole: its
  centre states that whole and every segment claims to be a share of it. With a
  currency that has no rate there is no such number, so the server sends no
  payload at all and the screen shows the per-currency figures, each group
  against its own total. There is no partial donut and no centre label standing
  for a total nobody computed.

- **The centre label is HTML, not something the chart library draws.** It is the
  server's ICU string like every other figure on the page, so nothing
  client-side divides a currency by a hundred — the contract the spend chart
  already kept. It lives inside the frame, which stays hidden until the chart is
  drawn, so a browser with no script never sees a number over an empty box.

- **A categorical palette had to be invented.** Every other hue in `tokens.css`
  carries a meaning — amber is money about to move, red is a problem, the accent
  is the thing to press — and a donut's third segment is not more urgent than
  its second. Six series and a tail, defined per theme, each held by test to 3:1
  on the card it is drawn on, with both themes required to define the whole set
  and no two entries allowed to resolve to the same colour.

- **Assumption: "notable" ranks on cost per month, converted.** Nothing computed
  it before, so the ranking is stated. Face value would make a yearly
  subscription look expensive because of its cycle; comparing the digits across
  currencies would rank 900 JPY above 50 GBP. So it is `monthlyMinor()`
  converted to the base currency, and anything whose currency has no rate is
  left out and counted — the answer year over year already gives to the same
  kind of gap. Each result is shown in **its own currency**: the conversion
  decides the order and nothing else.

- **A one-off, a lifetime purchase and a running trial are not ranked.** The
  first two have no monthly cost at all. The trial has none *yet*, and leaving
  it in would hand it "least expensive" every time at £0.00 — the figure the
  trials section on the subscriptions screen deliberately refuses to print.
  Pricing it at what it converts to was the alternative and is worse: this card
  and the KPI row would then report two different figures for one subscription
  on one screen.

- **Nothing the page carried was dropped.** The four per-period figures, the
  per-year-by-currency list and the cost-per-use ranking with its permission
  gate keep their place below the new sections, and the route stays `/stats` —
  the rail points Analytics at it, `g t` reaches it and the usage form returns
  to it.

- **A fault found by looking at the running application, not by a test.** A
  canvas inside a `display: none` parent measures zero, and Chart.js writes that
  onto the canvas as an inline `width: 0` it never revisits. The card's frame is
  hidden until a chart has been drawn in it, so every chart was being built
  against a box that did not exist. It survived Phase 10 because a resize
  happened to follow; at 390px on this screen nothing did, and both canvases sat
  at 0×0 with the figures hidden behind them. `drawInCard` now owns the order:
  library first, then reveal, then construct, and the frame goes back if the
  chart does not.

- **Known cost: three walks of the household for one page.** The statistics read
  every subscription, the screen service reads them again because the notable
  card and the usage ranking both want the rows, and year over year reads them a
  third time with its own argument. Sharing one read means changing what
  `StatsService` hands back rather than what it computes — a change to a service
  three screens depend on, and not a restyle's to make.
