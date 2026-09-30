# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 11 — my subscriptions

The subscriptions screen, restyled to the design's layout: a stats strip across
the top, the main list, and dedicated sections for what is expiring and what is
still on trial, with a category-spending widget alongside. This is the existing
list with a new arrangement and three pulled-forward sections — the data behind
each already exists.

## The stats strip

Three figures the application already has: **active count**, **yearly spending**
(per-currency, combined only when convertible), and **upcoming renewals** in the
near window. The design shows single clean numbers; the yearly figure keeps the
per-currency rule, so it may be more than one line, for the same reason as on the
dashboard.

## The list

The main list is the existing subscriptions list, restyled — not a new list.
That means it keeps, for free:

- **Saved views** — a named filter appears above the list as a link, stored as
  the query string the list itself produced and re-parsed through the same value
  object, so a saved or tampered view can no more reach the database than a
  tampered URL can.
- **Density** — comfortable or compact, the same markup with less padding, so a
  screen reader sees no difference between them.
- **Scope and permissions** — enforced in the repository and middleware, unchanged
  by the restyle.

## Expiring soon

The design's highlight cards for near-term items become cards for the two
deadlines Renovo actually distinguishes:

- **Renewing soon** — a charge inside the near window.
- **Cancel by** — the last day to give notice, shown only for a subscription that
  has a notice period, since without one the deadline *is* the renewal date and a
  second card would be noise. This is the deadline a person most needs surfaced,
  and it is the one the calendar feed exists to carry.

Each card's action respects permissions: the trigger is present only for a member
who could act on that subscription.

## Free trials

A section for trials before their conversion, showing what each will cost and the
day it converts — **the trial's last day is the day the first charge falls**, so
"14 days left" counts to that day and the cost shown is the price it converts to,
not a placeholder. A trial is the subscription it will become, not a separate
record, so acting on it here is acting on the subscription.

## Category spending widget

The breakdown the design shows in a sidebar (Entertainment, Music, Design, AI
Tools, and so on) is the category distribution the Statistics page computes,
rendered as the design's proportion bars. Percentages are of a per-currency total
where currencies differ; the widget does not blend unconvertible currencies into
one bar.

## Done when

The strip, list, expiring and trial sections and the category widget all bind to
real data; saved views and density still work; the cancel-by card appears only
where a notice period exists; trial dates and costs are correct; permissions gate
every action; the screen reflows on narrow viewports; both themes render; new
strings are catalogued; the quality gates and `i18n:check` pass.

## Decisions and assumptions

- **The sections are computed for a page, not for a keystroke.** The list still
  swaps through htmx, and that request takes a branch that runs the catch-up and
  nothing else. Folding the strip into the shared data would have re-run the
  household's statistics on every filter keystroke to arrive at figures the
  filter cannot change — the same reason the dashboard refuses to recompute its
  overview when a chip asks for eight rows.

- **`#subscription-list` stays the outermost element of its fragment.** The new
  arrangement wraps the list from the page, never from inside the partial,
  because the filter form selects that id and swaps it whole. A layout wrapper
  added inside the file would have been a working page and a broken filter.

- **One near window, now four consumers.** Fourteen days, referenced from
  `CancellationService::URGENT_DAYS` rather than declared again, so the strip's
  count, the renewing card and the cancel-by card are three readings of one
  query rather than three definitions waiting to disagree.

- **Cancel by is only where a notice period exists**, and that falls out of the
  existing service rather than a test in a template: `CancellationService` has
  always skipped a subscription without one, because without a notice period the
  deadline *is* the renewal date and the card beside it already says so.
  Deadlines already missed are kept and badged — nothing can be done about them,
  but the user has just been committed to another period.

- **The trials section is not a window.** `stats['trials']` is trials ending
  within thirty days, which answers a different question, so the repository's
  trial query gained an optional-null upper bound and the screen asks for every
  trial from today onwards. A trial converting in three months belongs to "what
  am I on a trial of" exactly as much as one converting on Friday.

- **An action is drawn only where it could be used.** Permission alone was not
  enough: reads are wider than writes, so under ISOLATED isolation a member can
  see a shared cost they contribute to without being able to change it.
  `Scope::mayWriteRow()` asks the question the repository's write predicate asks
  in SQL, and the cards consult it. It decides what to *draw*; the repository
  still decides what is *allowed*, and a forged POST meets the same refusal it
  always did. The list's own row controls are left as they were — this phase
  restyles the list, it does not change what it enforces.

- **The category widget states its denominator.** With every currency
  convertible it is the combined monthly total, which is what lets two
  categories be compared at all. With one currency lacking a rate it becomes a
  group per currency against that currency's own total, rather than nothing or,
  worse, one blended bar. The dashboard's version of the widget keeps the Phase
  10 behaviour — it draws nothing in that case — because changing it was not
  this phase's to decide; the *mechanics* they share (ordering, the top six, the
  named tail, the percentages) moved into `Support\Distribution` so the two
  cannot drift apart on the parts that are genuinely the same.

- **The per-currency money rule is written once.** The strip's yearly figure and
  the dashboard's spend tiles are the same rule — one line for one currency, a
  combined total when every currency converts, subtotals and no total when one
  does not — so the macro moved to `templates/partials/spend.twig` and both
  import it.

- **A fault found by looking at the running application, not by a test.** A
  header cell's visually-hidden label is absolutely positioned at its static
  place; on a table wider than the screen that place is hundreds of pixels to
  the right, and with no containing block on the scroll wrapper it resolved
  against the card, escaped the clip and pushed a phone-width page 160px
  sideways. `.table-scroll` and `.table-wrap` are now positioned, which fixes it
  on every screen that uses them, not just this one.

- **Urgency is one judgement, made once.** A cancel-by row is urgent when the
  cancel-by view says it is — its `is_urgent` is carried across rather than
  re-derived — so a row cannot be urgent on one screen and ordinary on the
  other. A renewing row is never singled out, because every row in that card is
  inside the near window already and marking them all would mark none. A trial
  is urgent when it converts inside that same window, which is worth saying
  because the trials section is not bounded by it.

- **Known cost: one extra walk of the household.** `CancellationService` reads
  every subscription again after the statistics have already done so, because
  its signature takes a scope rather than rows. Two full reads on a page that
  was already doing one; worth fixing when something else touches that service,
  not worth changing its API for a restyle.

- **Assumption: the action on a card is Pause.** Renovo has no "cancel"
  operation — pausing is how it records that you have stopped paying for
  something — so that is the trigger on the deadline and trial cards, posting to
  the endpoint that already exists rather than a new one.

- **Density needed no new mechanism** but did need the new rows to be built from
  the primitives it tightens; a test renders the sections at both densities and
  asserts the markup is identical, because "a screen reader sees no difference
  between them" is only true if there is no difference to see.
