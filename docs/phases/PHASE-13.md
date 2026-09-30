# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 13 — spend insights (optional)

This is the one phase that adds capability rather than restyling it, and the only
one that can slip without holding up the re-skin. The design's "AI Insight" hero
card — *"cancel Adobe Stock, save $540/year"* — describes a feature the
application does not have. This phase is where it is either built honestly or left
out, and it is written so that leaving it out costs Phases 8–12 nothing.

## Rule-based, not a model

The card is worth having; the word "AI" on it is not what makes it work. Every
example the design gives is a deterministic observation about the household's own
subscriptions — two tools in the same category, a trial about to convert, a price
that has risen, spend that a cancellation would remove. A small set of rules over
data the application already holds produces exactly these, with three properties a
model would not give for free: it invents nothing, it can name the precise
subscriptions behind every figure, and the "save $X/year" is a real arithmetic
result the member can check. That is the version to build. A model-backed version
can come later behind the same card; it must clear the same bar — no figure it
cannot source, and every recommendation traceable to specific rows.

## What the rules look for

A first, defensible set — each a signal already latent in the data:

- **Overlap** — more than one active subscription in the same category (the
  design's "multiple design tools"), surfaced with the cheaper-to-drop option and
  the annual figure dropping it would remove.
- **Trial converting soon** — already a notification; surfaced here as a decision
  with its cost attached.
- **Price risen** — a subscription whose price history shows an increase, or a
  scheduled increase not yet in effect, so the member sees it before the charge.
- **Rarely used** — only if the usage signal is present; absent it, this rule
  stays silent rather than guessing.

Each insight states the figure and the subscriptions it came from. "Save
$540/year" without the rows behind it is the kind of confident, unsourced number
this application avoids everywhere else, and the card holds to that standard.

## The figures obey the money rules

A saving is money, so it is minor units until display and per-currency when the
subscriptions it spans do not share one — an insight does not blend
unconvertible currencies into a single headline saving. The "Review subscription"
action links to the real subscription, permission-checked; a member sees insights
only for subscriptions in their own scope.

## Where it lives

The hero card sits on the analytics screen (Phase 12) and, optionally, as a
dashboard tile. It reads through the existing services and adds a single
insight-deriving service beside them; it writes nothing and schedules nothing, so
it cannot misfire the way an alert could.

## Done when — or not at all

If built: every insight names its subscriptions and its arithmetic checks out;
savings follow the per-currency rule; the action is permission-scoped; both themes
render; strings are catalogued; the quality gates and `i18n:check` pass. If
deferred: the card is simply absent, Phases 8–12 are unaffected, and this document
records why it was the right thing to leave for later — a genuinely new feature has
no place being rushed in behind a visual re-skin.

## Decisions and assumptions

Built, not deferred. The rules below all read data the application already held,
so the card states figures a member can check rather than figures it invented.

- **It is not called "AI", because it is not one.** The design's hero is
  re-pointed at `SpendInsightService`: a handful of rules over the household's
  own rows, each naming the subscriptions behind its figure. That is what makes
  "£299.88 a year" checkable, which is the property the card exists for. A
  model-backed version can come later behind the same card if it clears the same
  bar.

- **The figure is one subscription's own money, in its own currency.** There is
  no headline totalling the insights, because that total would blend currencies
  the rest of the application refuses to blend. Conversion happens once, to put
  the list in an order, and appears nowhere on screen. An annual figure is
  `yearlyMinor()` rather than twelve monthly ones, so a weekly plan's arithmetic
  survives the multiplication.

- **Urgency orders the list, not size.** A trial converting on Friday stops
  being actionable on Friday; an overlap is as actionable next month as it is
  today. Ranking on the amount would put a £4 trial below a £300 overlap and let
  it convert. Within a kind the larger figure leads, unconvertible ones sort
  last rather than being ranked on their digits, and name breaks the remaining
  ties so the card does not shuffle between page loads.

- **Assumption: the overlap names the cheaper of the pair**, as this phase's
  brief asks. The application does not know which of two design tools somebody
  actually uses, so it does not pretend to: it says how many are in the
  category, names them, and attaches the annual cost of the cheaper — the least
  that dropping one removes, and exact, which "save up to £360" would not be.
  Uncategorised subscriptions are not an overlap: two rows sharing the *absence*
  of a category share nothing, and the rule would otherwise fire on every
  household that has not categorised anything.

- **Silence is a result.** Rarely-used says nothing without a usage signal —
  unmeasured is not unused. A category whose currencies cannot be compared
  produces no overlap, because there is no defensible "cheaper". A trial with no
  cycle recorded for after conversion has no annual figure. And when no rule
  fires there is no card at all: a featured card reading "nothing to report"
  would take the most prominent place on the screen to say nothing.

- **A converted trial is not a price rise, and the flag cannot tell you that.**
  Conversion clears `is_trial` and writes the paid price in the same
  transaction, and the screen's catch-up runs before these rules read a row — so
  by the time they see it, the last two history rows read "£0, then £12.99". The
  guard is `PriceChangeSource::TrialConversion` on the current row, which is
  what that column is recorded for. The next increase after the conversion
  reports normally.

- **One bulk read for price history.** `PriceHistoryRepository` gained
  `findAllBySubscription()` rather than this asking per subscription on a page
  that already walks the household three times. It reads whole histories rather
  than a recent window because a rise is a comparison between two rows, and a
  window holding the new price but not the old one cannot tell a rise from a
  first price.

- **The usage ranking is computed once.** `AnalyticsScreenService` now derives
  the value signals and hands them to both the insight rules and the card at the
  foot of the page, so the controller no longer computes them a second time and
  "rarely used" means one thing on one screen.

- **Deliberately left for later: the dashboard tile.** The brief makes it
  optional, and a new feature is better judged on one screen before it is put on
  two.
