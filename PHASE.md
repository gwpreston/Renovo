# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 24 — budgets

A dedicated Budgets screen, reached from the rail's Household tools. Budgets
already exist (Phase 2) and gained named-member and household subjects in Phase
20; this phase gives them the prototype's page and form. Projections still come
from `ForecastService`, so a budget cannot disagree with the forecast; the history
chart comes from `SpendHistoryService`, so it cannot disagree with the dashboard.

## Depends on

- **Phase 20** — `budgets.subject_user_id` (member and household budgets) and
  their permission rules.
- **Phase 21** — `SpendHistoryService`.
- **Phases 2, 3** — budget projection, warning threshold, the alert crossing
  state, per-user routing.

## In scope this phase (build ONLY these)

### A. The page

- An intro line: each budget compares a limit with what subscriptions will cost
  in the period, including trials about to convert; you are alerted when one is
  projected over.
- **New budget** — a secondary button in the page header (the top bar's
  "Add new" stays the one primary action), shown only to roles that may create a
  budget.

### B. Status tiles

**On track**, **Warning** (past its warning threshold), **Projected over**, and
**Household limit** — the monthly household budget if one exists (SHARED), else
the tile is absent. Counts are of budgets the viewer can see.

### C. Budget cards

One per visible budget:

- Name; meta line "{Monthly|Yearly} · {category or All categories} · {member
  name or Household}".
- State badge with text: On track (ok) / Warning (warn) / Over (bad).
- **Projected** value "of {limit}" and a bar showing charged-so-far solid and
  projected lighter, with a tick at the warning threshold.
- Note: "{amount} left", "{N}% used — past the {W}% warning", "Over by
  {amount}", or "Projected {amount} if trials convert — over by {amount}".
- Percentage.
- **Alerts line** from the owner's real routing: "Alert when projected over ·
  Email, Slack", or "Alerts off" — never a fixed string. (Only projected-over
  alerts are sent; the warning threshold is shown, not alerted — Phase 3's rule.)
- Edit, drawn only where the viewer may edit that budget.

Budgets are in base currency. A budget whose projection is `null` (spend in a
currency with no rate) shows "Projection unavailable — no rate for {currency}"
instead of a figure, matching the alert state machine's refusal to call it under.

### D. Household total, last six months

Bars of reconstructed monthly spend for the last six months against the monthly
household limit, "over in N of 6". Shown only when a monthly household budget
exists, in SHARED mode. Captioned as reconstructed, with the excluded count.

### E. The budget form (modal, full page without script)

- Name
- Limit ({base currency})
- Period — Monthly / Yearly
- Category — All categories, or one flat category (decision 4)
- Whose spending — **Household** (SHARED only) or a member; a Contributor sees
  only themselves; an Editor in ISOLATED only themselves
- Warn me at — slider, with its percentage
- Delete (edit only), Cancel, Save budget

## Data-model changes

**None** (Phase 20 added the subject).

## Explicitly out of scope

- Per-payment-method budgets (Phase 17's seam, still a seam).
- Category-group budgets (decision 4).
- Alerting at the warning threshold.

## Decisions & assumptions (settled before build)

- **New budget is secondary**, beside a primary "Add new" in the top bar.
- The alerts line describes the **owner's** routing, as **channel types only**
  ("Email, Slack") — never a channel's name, address or URL, since every member
  who can see the budget reads it.
- The six-month history appears only with a monthly household budget.
- **Cards read the calendar period**: this month for a Monthly budget, this
  calendar year for a Yearly one — charged so far (reconstructed, to
  yesterday) plus the forecast to the period's end, as the dashboard's budget
  card already reads the month. The projected-over alert (Phase 3) still
  projects a rolling horizon from today, so a card can turn Over on a different
  day from the alert; aligning the two is a seam, not this phase.
- **Over** is the projection including trials converting; the note tells "over
  on what is committed" from "over only if trials convert".
- **No Active toggle** in the form: Delete replaces deactivating, an edit leaves
  `is_active` as it is, and an inactive budget stays hidden as today.
- **New budgets are in base currency**; an existing budget in another currency
  keeps it on edit, and its limit is labelled with that currency.
- **Warn me at** defaults to 85%; a budget with no threshold draws no tick.

## Status

- [x] Page + intro + New budget (permission-gated)
- [x] Status tiles (scoped counts)
- [x] Budget cards: projected bar, warn tick, notes, real alerts line,
      unavailable projection
- [x] Six-month reconstructed history (SHARED, household budget)
- [x] Form with subject rules
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check` green on both engines

Notes from the build:

- `BudgetMonthService::all()` reads every visible budget over its calendar
  period; the dashboard's `thisMonth()` shares the same read, so a monthly
  budget has one set of figures on both screens. `BudgetScreenService` turns
  those reads into states, notes, bar widths, the alerts line and the tiles.
- **The warning compares minor units**, not the rounded percentage, so 84.5%
  is not past an 85% threshold. This changes the dashboard's budget card the
  same way. The percentage on a card rounds down while under the limit and up
  once over, so it never shows a line the state has not crossed.
- The history is `SpendChartService::window(5, 0)`: five reconstructed months
  plus this month (charged plus still due), as on the dashboard. "Over in N"
  counts this month's total.
- Edit is drawn only where `Scope::mayWriteRow()` allows, and the edit form
  answers 404 for a budget the viewer may read but not write (a Contributor
  and the household's budget).
- The form is one dialog for New and Edit, loaded from the form's own page
  (`openRemote()` in `public/assets/app.js`, generalised from quick-add);
  without script the links go to the full page. The Active toggle and the
  currency select are gone (see Decisions).
- New budget is `button-quiet`: in this app `button-secondary` is the ink
  fill the top bar's Add new wears. On a phone, a header action with an icon
  shows only the icon (`.button-label`).
- `budget_period.*` now reads Monthly / Yearly; the old strings (rolling
  windows, "alerts arrive in a later version") are removed.

## Definition of done

The page shows only budgets the viewer may see, with projections equal to the
forecast and history equal to `SpendHistoryService`; subjects obey Phase 20's
rules in both modes; states and notes are correct at the boundaries; nothing is
invented where no budget exists; all palettes × themes, wide and narrow; gates
green on both engines. Then update `PHASE.md` to the next phase.

## Tests

- Projection equals `ForecastService` for the subject and period.
- State boundaries: just under the threshold, at it, just over the limit, and
  over only if trials convert.
- A `null` projection renders "unavailable" and never "on track".
- Subject options: Contributor sees only themselves; Household absent in
  ISOLATED; a forged POST for a disallowed subject is refused.
- The alerts line reflects the owner's routing and changes when routing changes.
- Viewer: no New budget, no Edit, 403 on every mutating endpoint.
- `AccessibilityTest` passes on the page and the form.