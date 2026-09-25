# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 25 — the calendar

The billing calendar rebuilt to the prototype: a month grid with a chip per
charge, a selected-day panel, month totals and the calendar-feed card. It still
reads the forecast (Phase 6's rule), so a scheduled rise shows the amount that
will actually be taken and the calendar agrees with the dashboard and budgets.
It is also where the bell in the top bar now points (Phase 19), so it is the
"what's coming up" screen.

## Depends on

- **Phase 6** — `CalendarGrid` (week-start aware), the forecast-driven month,
  no paging before the current month.
- **Phase 5** — the iCal feed and its read-only token.
- **Phase 20** — Paused and Cancelled excluded; private rows only for the payer.
- **Phase 2** — cancel-by dates from notice periods.

## In scope this phase (build ONLY these)

### A. Header

Month title (ICU), "N charges · {total}" (per-currency rule), **Today**, and
previous/next. **Previous is disabled on the current month**: there is no ledger
to page back into (Phase 6's decision, unchanged).

### B. The grid

- Weekday headers from the account's week-start preference (the prototype's
  fixed Monday is not kept).
- Each day: date number (today marked with the accent and "Today" as text for
  screen readers), up to three chips, "+N more", and the day's total.
- **Three chip kinds**, each with a word or icon, not colour alone:
  **Charge** (accent-soft), **Trial ends** (info), **Cancel by** (warn) — the
  last is new to the grid and comes from `CancellationService`. The prototype's
  "£100 or more" highlight is not built (decision 19).
- Days outside the month are dimmed and inert.
- Paused and cancelled subscriptions do not appear; a private row appears only
  for its payer.

### C. The selected day

Clicking a day (htmx; `?day=` without script) shows the panel: "{weekday} {day}
{month}", total, and each item — logo/initial, name, what it is ("Trial ends —
converts to paid", "Cancel by — notice period 30 days", "Plan · Payment
method"), owner initials, amount in its own currency and ≈ base. Empty state:
"Nothing due on this day". Defaults to today in the current month, else the first
day with anything due.

### D. Month summary

**Month total**, **Charges** (count), **Heaviest day** (date · amount) — all
per-currency; the heaviest day is chosen on the converted amount and shown in
base currency, or omitted when a day cannot be combined.

### E. Narrow screens

Below 768px the grid becomes a list grouped by day (the prototype's mobile form),
emitting the weekday attributes Phase 14 required, with the same chip kinds.

### F. Calendar feed card

"Subscribe in Google Calendar, Apple Calendar or Outlook to see renewals, trial
conversions and cancel-by deadlines." The feed URL in a read-only input (select
all to copy without script) with a **Copy** button; **Create a new link**, which
revokes the old read-only feed token and issues a new one (existing token
service), confirming first because every subscribed calendar will stop updating.
The feed respects private rows and excludes paused and cancelled ones.

## Data-model changes

**None.**

## Explicitly out of scope

- Paging into past months.
- A week or day view.
- An amount-threshold highlight (decision 19).

## Decisions & assumptions (settled before build)

- **Cancel-by deadlines join the grid** as a third chip kind, read from
  `CancellationService` and kept only where the subscription is already in the
  forecast set the grid is drawn from — so "Just mine" and scoping apply to them
  as to charges. A deadline already passed is not drawn.
- **The feed link is shown once.** Feed tokens are stored only as a hash, so the
  full URL appears in the card right after **Create a new link** (the one-shot
  flash the API-token page uses); other visits say when the link was created and
  last used. The feed token is the user's read token under a fixed reserved name;
  "Create a new link" revokes those and issues one. Read tokens made on the
  tokens page are not touched. No data-model change.
- **The week start follows the account preference.**
- **Months outside the range are refused**: before the current month, or past
  the horizon, is a 404; a malformed `?month=` falls back to the current month.
- **"Just mine" is kept** in the page actions.
- **Create a new link** is a web route acting only on the user's own token, so
  it names no permission (the API-token routes' precedent) and is not part of
  the versioned API.

## Status

- [ ] Header with totals; previous disabled on the current month
- [ ] Grid: week start, today, three chip kinds, +N more, day totals
- [ ] Selected-day panel (htmx + `?day=`)
- [ ] Month summary
- [ ] Narrow list layout with weekday attributes
- [ ] Feed card: copy, create a new link (revoke + issue)
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

## Definition of done

The calendar shows every charge, trial end and cancel-by deadline the viewer is
entitled to see, at forecast amounts, from the right first weekday; totals follow
the per-currency rule; the feed can be copied and replaced; all palettes ×
themes, wide and narrow; gates green on both engines. Then update `PHASE.md` to
the next phase.

## Tests

- Grid starts on the preferred weekday (Sunday and Monday).
- A scheduled rise shows the new amount from its effective date.
- Cancel-by chips appear only for subscriptions with a notice period.
- Paused, cancelled and (for non-payers) private rows never appear, in the grid
  or the feed.
- Previous is disabled on the current month and the route refuses earlier months.
- Creating a new feed link invalidates the old token immediately.
- `?day=` renders the panel without script.
- `AccessibilityTest` passes; chips are distinguishable without colour.