# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 20 — the data-model additions the new screens need

The prototype assumes six things the application does not yet record or do. They
were decided one by one (decisions 2, 5, 8, 9, 10 and 18) and are built here,
**before any screen is rebuilt**, so Phases 21–28 display real behaviour rather
than inventing it. Each addition goes through the existing service and repository
layers, the central scoping layer, the API, backup/restore and the tests — the
same edges Phase 17 moved for payment methods.

No screen is redesigned in this phase. Where an addition needs a control to be
usable at all (a field on the existing subscription form, a toggle in the existing
notification preferences), the control is added to the **current** page in its
current style; the redesigned versions arrive with each screen's own phase.

## Depends on

- **P1** — the scoping layer (`AbstractScopedRepository`, `Scope`), the
  subscription model, `is_active`.
- **P2** — budgets and `ForecastService`, splits and their read-only widening,
  price history and `CatchUpService`.
- **P3** — the alert dispatcher, `notification_log`, digests, per-user routing.
- **P5** — the API, OpenAPI, `docs/api.md`, backup/restore, the importer.
- **P13** — `PriceChangeSource`, used to tell a real rise from a trial conversion.
- **The Contributor role** — already built; the permission rules below include it.

## In scope this phase (build ONLY these)

### A. "Only payer" visibility (decision 2)

A subscription can be marked private to its payer. It is hidden from **everyone
else in the household, in either isolation mode**, including Owner/Admins.

- **Column** `subscriptions.visibility` — string, `household` (default) or
  `payer`. Existing rows become `household`.
- **Scoping.** The read predicate gains
  `(visibility = 'household' OR owner_user_id = :viewer)`, applied **after** the
  split-participant widening so no widening can re-expose a private row. Because
  it lives in the one scoping layer, every consumer inherits it: the list, search,
  stats, forecast, budgets, calendar, iCal feed, insights, the API, bulk actions,
  tags and price-history decoration.
- **Totals.** A private row counts only in its payer's figures. Other members'
  household totals, budgets, forecasts and charts leave it out — its cost is not
  visible by subtraction.
- **No splits.** A private subscription is paid by one person. Validation rejects
  `visibility = payer` with any split, and rejects adding a split to a private
  row. (A split must be visible to its participants — a standing rule in
  CLAUDE.md — so the two cannot coexist.)
- **Alerts** for a private row go to its payer only (they already follow scope).
- **Member removal** (Phase 15): a departing member's private rows are always
  handled by the reassign-or-delete prompt, in SHARED as well as ISOLATED mode,
  because nobody else can see them to take them over silently.
- **API**: `visibility` in the payload, OpenAPI and `docs/api.md`; an absent
  value on PUT keeps the current setting (the Phase 17 precedent).
- **Backup**: see the open decision below.
- **Form**: a "Visible to: Household / Only me" control on the current form;
  choosing "Only me" disables the split control.

### B. "Paused" is the name for inactive (decision 5)

No schema change. A subscription with `is_active = false` and no
`cancelled_at` is **Paused** everywhere the interface names a state: badges, the
status filter, the stats strip. The existing pause/resume action is renamed to
match. A paused row stays out of recurring totals, the forecast, reminders and
the calendar, as inactive rows already do.

### C. Cancelled (decision 18)

A cancelled subscription is finished, unlike a paused one that may resume.

- **Column** `subscriptions.cancelled_at` — nullable date.
- **Cancel** (`SubscriptionService::cancel()`): sets `cancelled_at` to today and
  `is_active` to false in one transaction. On a trial it also stops the
  conversion: `CatchUpService` skips cancelled rows, so a cancelled trial never
  becomes a paid subscription. Available for any subscription; the redesigned
  screens surface it on trials first ("Cancel trial").
- **Undo**: clearing `cancelled_at` returns the row to Paused (not Active), so an
  accidental cancel cannot silently restart charges.
- **Status order**, derived in one place: Cancelled → Paused → Trial → Active.
- Cancelled rows are excluded from totals, forecast, budgets, reminders,
  calendar and feed; listed under a **Cancelled** filter; still deletable.
- Permission: the same as editing the subscription (`mayWriteRow`); a Viewer
  gets 403.
- API field `cancelled_at` (read-only in the payload; cancelling and undoing are
  `POST /api/v1/subscriptions/{id}/cancel` and `.../uncancel`), OpenAPI and
  `docs/api.md`; backup/restore carries it.

### D. Plan (decision 10)

- **Column** `subscriptions.plan` — nullable string, 60 ("Standard", "Family").
- On the form, list and detail; in the API, OpenAPI, `docs/api.md`,
  backup/restore, and as an optional mapped column in the CSV/JSON importer (a
  plain text field, so trivial there).

### E. Budgets for a named member, and for the whole household (decision 8)

Today a budget is owned by a member and measures **that member's share**. The
prototype's "Whose spending" adds two things: an Owner/Admin setting a budget for
someone else, and a budget over the whole household.

- **Column** `budgets.subject_user_id` — nullable FK to `users`. Backfilled to
  `owner_user_id`, so every existing budget keeps measuring exactly what it did.
  `null` means **household**: the whole household's spend, not one share.
- **Household budgets exist only in SHARED mode.** In ISOLATED mode nobody may
  see the whole household's spend, so the option is not offered and the service
  refuses it; an existing household budget on an instance switched to ISOLATED
  is shown to its owner as unavailable rather than computed from a partial view.
- **Who may set what**: Owner/Admin and Editor — any subject (Editor only in
  SHARED for others, since in ISOLATED they cannot see others' rows);
  Contributor — themselves only; Viewer — none (403).
- **Visibility**: SHARED — household-wide, as now; ISOLATED — the owner and the
  subject.
- **Projection** still comes from `ForecastService`, now asked for the subject's
  share or for the household; private rows follow section A (a household budget
  counts a private row only for the payer viewing it — so a household budget is
  computed from the scope of whoever views it, and the alert state machine
  evaluates it from the **owner's** scope).
- **Alerts** go to the owner and, when different, the subject — each by their
  own routing preferences. The existing crossing/re-arm state is unchanged.
- API, OpenAPI, `docs/api.md` and backup/restore carry `subject_user_id`
  (matched by email on restore, like other member references).

### F. "Price change" alert type (decision 9)

A new alert, on the existing dispatcher, for "a price was recorded or scheduled".
This deliberately supersedes Phase 16's "no new alert types" for this one case.

- **Fires once per price-history row** that is a genuine change: a manual price
  edit or a newly scheduled future price. **Not** for a subscription's first
  price, a trial conversion (`PriceChangeSource::TrialConversion`) or a bulk
  currency conversion (the amount changed, the price did not).
- **Occurrence key** = the price-history row id, so a re-run is silent. Sent on
  the next scheduler run after the row is written; a scheduled rise produces one
  alert when it is scheduled, not another when it takes effect.
- **Recipients**: users who can see the subscription (scope decides), with the
  alert enabled.
- **Preferences**: a "Price changes" row in the **existing** per-user routing and
  a toggle, default **on**, routed like renewals. Digest users get a
  "Price changes" section in their digest.
- New `AlertType` case, catalogue keys, and message text naming old price, new
  price, effective date and yearly effect — in the subscription's own currency.

## Data-model changes

Migrations, sequenced after the last existing one, each with an explicit
`down()`, verified on PostgreSQL and MySQL:

1. `add_visibility_to_subscriptions` — `visibility` string(10), default
   `household`, not null.
2. `add_cancelled_at_to_subscriptions` — nullable date, indexed.
3. `add_plan_to_subscriptions` — nullable string(60).
4. `add_subject_user_id_to_budgets` — nullable FK to `users`
   (`ON DELETE CASCADE`, matching how a departing member's own budgets already
   go), indexed; data step backfills `subject_user_id = owner_user_id`.

No new table. The price-change alert reuses `notification_log`.

## Explicitly out of scope (leave clean seams, do NOT stub)

- A `paused_at` date (pausing stays a flag; past-spend reconstruction treats
  paused rows as Phase 2's year-over-year already does).
- Category groups (decision 4 — categories stay flat).
- Any screen redesign — Phases 21–28.
- "Spend removed by cancellations" as a figure (Phase 8's "Total Savings" seam)
  — `cancelled_at` makes it computable later; not built now.

## Decisions & assumptions (confirm or correct before build)

- **Backup and private rows — decided: (b).** A backup **excludes** other
  members' private subscriptions and states how many were left out (export
  screen and manifest). It keeps the backup's standing rule — an archive holds
  what the exporter can see, as ISOLATED already does — and avoids the one
  unscoped read that would have let an Owner open a private row.
- Cancelling is available for **every** subscription, surfaced first on trials.
- Undoing a cancel returns the row to **Paused**, not Active.
- Household budgets are **SHARED-mode only**.
- The price-change alert defaults **on**.

## Status

- [x] Migrations 1–4 (and 5–6, below) apply and roll back on both engines
- [x] Visibility: column, scoping predicate after widening, no-split rule,
      alerts, removal prompt, form control
- [x] Paused: status derivation and labels
- [x] Cancelled: cancel/undo service + routes, catch-up skip, exclusions,
      Cancelled filter
- [x] Plan: column, form, list, importer mapping
- [x] Budget subject: column + backfill, household (SHARED) and member
      subjects, permissions, alert recipients
- [x] Price-change alert: type, finder, idempotency, preferences row, digest
      section
- [x] API + OpenAPI + `docs/api.md` for visibility, cancelled_at (+ cancel /
      uncancel), plan; coverage tests green (`subject_user_id` has no API —
      there is no budgets endpoint)
- [x] Backup/restore carries all four columns
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, API coverage green on both engines

## Built as approved, with these decisions

- **Two more migrations.** `saveRoutes` deliberately never subscribes anybody
  to a new alert type, so "default on, routed like renewals" needed both a
  place for the toggle and routes to exist:
  `add_price_change_alerts_to_notification_preferences` (boolean, default
  true) and `copy_renewal_routes_to_price_change` (each renewal route gains a
  price-change sibling; idempotent; `down()` deletes them). A preferences save
  that does not name the toggle keeps it.
- **Budgets: nobody measures another member in ISOLATED mode**, Owner/Admins
  included — reads there are fenced by mode, not role, so the spec's reason for
  excluding Editors applies to everybody. A budget set for somebody else before
  a switch to ISOLATED shows its owner "unavailable", is visible to its subject
  through a read-only widening on `BudgetRepository`, and is not evaluated for
  alerts (its state row belongs to the owner, who cannot measure it).
- **Budget alerts.** The crossing is evaluated once, in the owner's run and
  scope; a subject who is not the owner is sent the recorded crossing in their
  own run, keyed on its date. A subject whose run precedes the owner's hears on
  the next run. A household budget's breach goes to its owner only.
- **The owner of a budget is whoever set it.** The form's picker is now "Whose
  spending" (`subject_user_id`: a member, `household`, or absent); an edit no
  longer moves the owner, and a form without the picker keeps the subject.
  The dashboard's usage card shows the budget whose *subject* is the viewer.
- **"Only me" means the actor.** Allowed only when the owner is the person
  saving it, the payer is unset or the owner, and the row is not split. A
  restore may put a private row back with the member it belonged to.
- **Privacy applies to writes too.** The clause is AND-ed onto the write
  predicate as well as the read one, so bulk actions, pause, cancel and delete
  cannot reach another member's private row. Price history and attachments
  follow their subscription through a `NOT EXISTS` on the parent.
- **The web list hides cancelled rows by default**; a new Status filter (All /
  Active / Trial / Paused / Cancelled) finds them. The API keeps `inactive=1`
  meaning paused *and* cancelled, gains a `status` parameter, and the resource
  gains a read-only `status`. The strip's "Paused" figure excludes cancelled
  rows, and the list's Pause/Resume labels now come from the catalogue.
- **Cancel** is on every row (labelled "Cancel trial" on a trial), with a
  confirm; a cancelled row offers "Undo cancel" instead of Pause/Resume.
  Resume, a form or API save, a bulk resume and an import cannot reactivate a
  cancelled row.
- **Member removal.** In SHARED mode a departing member's private rows get
  their own reassign-or-delete question; reassigned, they stay private (to the
  new owner) and lose a payer. Budgets that measure the departing member are
  deleted, whoever set them; budgets they set for others or the household move
  to the admin like every other row.
- **Price-change digests** include only changes recorded since the user's
  previous *delivered* digest (from the ledger), so a change is never in two
  digests and a digest that failed does not swallow what it carried; with no
  delivered digest yet, a month back. Immediate users look back seven days and
  the ledger makes each change once — which also means the first run after
  upgrading announces manual and scheduled edits from the previous week.
- **The "Visible to" control** is a `role="radiogroup"` rather than a nested
  fieldset, which `SubscriptionFormLayoutTest` forbids; a `.group-label` class
  styles its name as a label.
- **Not done:** demo seed and dev-setup sample data do not yet use plans,
  private rows or cancellations; a private row restored for another member
  loses its attachments (the restorer cannot write to it).

## Definition of done

Migrations apply and roll back; a private subscription is invisible to every
other member in both modes and absent from their totals; paused and cancelled are
distinct, correctly derived and correctly excluded; a cancelled trial never
converts; plan round-trips; budgets can measure a named member or (in SHARED) the
household; a price change sends exactly one alert; the API and backup carry every
new field; all gates green on both engines. Then update `PHASE.md` to the next
phase.

## Tests

- **Visibility:** another member — Owner/Admin, Editor, Contributor, Viewer —
  cannot list, find, search, total, forecast, calendar, feed or API-read a
  private row, in SHARED and ISOLATED; the payer can. A private row cannot gain
  a split and a split row cannot become private. Split widening does not expose a
  private row (fails without the predicate ordering).
- **Totals:** a household total viewed by a non-payer equals the total without
  the private row.
- **Cancelled:** cancelling a trial stops conversion on the next catch-up;
  undo returns Paused; cancelled rows leave totals, forecast, budgets, reminders
  and calendar; status derivation order holds; Viewer 403.
- **Plan:** persists through form, API, import and backup.
- **Budgets:** backfill leaves existing projections unchanged; a member budget
  measures the subject's share; a household budget is refused in ISOLATED;
  Contributor cannot set one for another member; alerts reach owner and subject.
- **Price change:** exactly one alert per genuine change; none for first price,
  trial conversion or currency conversion; re-run sends nothing; routing and the
  toggle are honoured; digest aggregation includes it.
- **Regression:** the Phase 2/3 suites (splits, catch-up, budget de-dup/re-arm,
  idempotency) stay green.