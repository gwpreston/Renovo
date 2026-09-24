# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 22 — subscriptions: the list and the form

The subscriptions list and the add/edit form, rebuilt to the prototype. The list
stays the **existing list** — same filter value object, same scoping, same htmx
fragment — with a new arrangement. The form stays the **one definition** of a
subscription: quick-add, the full page and the edit screen all render it. The
prototype's form is shorter than the application's, so this phase keeps every
field the application already has and arranges the less-used ones behind a
"More details" section rather than dropping them.

## Depends on

- **Phase 20** — visibility (lock icon, "Only me"), Paused/Cancelled status and
  filters, the Plan field, the cancel action.
- **Phases 11, 17** — the list's sections and value object, saved views, density,
  bulk actions, the payment-method badge and select.
- **Phase 6** — logo fetching from `website_url`, saved views.
- **Phase 2** — splits, scheduled price changes, trial conversion fields.

## In scope this phase (build ONLY these)

### A. The stats strip

Four tiles: **Active**, **Trials**, **Paused**, and **Per month** (recurring
monthly total — per-currency rule, so it may be two lines or subtotals with no
combined figure). Replaces Phase 11's strip.

### B. The toolbar

- **Search** — the list's existing `q`.
- **Category chips** — flat categories (decision 4), plus All.
- **Status** — All / Active / Trials / Paused / **Cancelled**.
- **Scope** — Household / Mine. "Mine" means rows the viewer pays for or shares a
  split in. Hidden when the viewer can only see their own rows anyway (ISOLATED).
- **Saved views** — a menu beside the filters listing named views, and "Save
  this view" (existing mechanism, re-parsed through the value object).
- **Density** toggle and **Export** (existing) at the end of the toolbar.
- A summary line: "N of M · {per-month total}/mo", per-currency rule.

Every control is a link or a GET form, swapping `#subscription-list` through
htmx and working as a full reload without script, as today.

### C. The table

| Column | Content |
| --- | --- |
| Select | checkbox for bulk actions (existing), with the bulk bar appearing on selection |
| Service | logo (cached logo, else initial tile), name, 🔒 when private, second line "Plan · Payment method" |
| Category | name |
| Paid by | owner initials + name; split note — "Split equally with Tom", "Split 3 ways", "Custom split" |
| Price | amount and cycle in own currency; "≈ {base}" beneath when different |
| Monthly ({base}) | monthly equivalent in base currency; "—" with a note when no rate |
| Next charge | ICU date; "in N days", "trial ends", or the cancel-by date when inside the near window |
| Status | badge with text: Active (ok), Trial (info), Paused (warn), Cancelled (neutral), Renewing soon (warn) |
| Actions | Edit; Pause/Resume; Cancel/Undo; Delete — each drawn only where `mayWriteRow` and the role allow, each refused server-side otherwise |

Below 768px the table becomes a card list (name, owner · next charge, amount,
status) with the same actions in a menu.

One-off and lifetime entries appear in the list with their type in the Price
column and are excluded from the Per month figure, as everywhere.

### D. What stays from Phase 11, and what moves

- **Cancel-by deadlines** stay on this page: a compact card beneath the list,
  from `CancellationService`, linking to `/cancellations`. It is the one deadline
  the rail no longer points at directly.
- **Renewing soon** becomes the status badge in the table (section C).
- **Free trials** and the **category widget** move to the dashboard (Phase 21),
  which carries both.

### E. The form

Opened as the quick-add modal (the real form, loaded through htmx) or as a full
page without script. Header: "Add subscription" / "Edit {name}", subtitle "Price,
renewal date, who pays and who can see it".

**Always shown** (the prototype's fields):

- Service name*, Plan
- Price*, Currency (full ISO list, not three), and a live "≈ {base} at today's
  rate" note computed **by the server** (htmx on change — no money arithmetic in
  JavaScript)
- Category (flat list)
- Billing cycle — Weekly / Monthly / Quarterly / Yearly / **Custom** (reveals
  "every N days") — and **Type**: Recurring / One-off / Lifetime
- Next charge
- Payment method (Phase 17 list — plain labels)
- Remind me — "Use my defaults", "Never", or chosen days (1, 3, 7, 14, 30; more
  than one allowed), mapping onto the three-state `reminder_days`
- Paid by — member chips
- Cost split — Payer only / Split equally / **Custom shares** (reveals a weight
  per member, allocated by largest remainder as today)
- Visible to — Household / Only me (disables split, per Phase 20)
- Free trial toggle → Trial ends, **Converts to** price and cycle

**More details** (collapsed by default, a `<details>`):

- Notice period (days/months) — the cancel-by source
- Tags
- Website (drives the logo fetch), logo upload/clear
- Start date — with the hint that past spend is reconstructed from it
- Notes

**Edit only**:

- Price history — recorded and scheduled rows, newest first, with
  **Schedule a price change** (effective date + new price), through the existing
  route
- Attachments (invoices/receipts) — existing upload and list
- Delete, Cancel subscription / Undo cancel

Usage and rating stay on the Analytics cost-per-use card, where they are edited
today.

## Data-model changes

**None.** Every field exists (Phase 20 added the last three).

## Explicitly out of scope

- New subscription fields beyond Phase 20's.
- Changing what bulk actions do (only their placement).
- Renaming categories inline (Settings, Phase 28).

## Decisions & assumptions (confirmed 2026-09-24)

- Every existing field is kept; the less-used ones sit under **More details**.
- **Cancel-by** stays on this page as a compact card; trials and the category
  widget move to the dashboard.
- The status badge "Renewing soon" uses the one near window
  (`CancellationService::URGENT_DAYS`).
- **Export — a CSV of the filtered list.** There was no list export (the only
  export is the household backup). A new web-only GET route reads through the
  same `SubscriptionFilter` and scoping as the list, so the file holds exactly
  the rows the viewer could page through; `ViewSubscriptions`, no API route.
- **"Paid by" is the owner.** The form's member chips set `owner_user_id` —
  locked to the viewer when `restrictsWritesToOwner()`, as today — and the list
  column shows the owner. The separate payer field moves under **More details**
  as "Paid by someone else". **"Mine"** is rows the viewer owns or shares a
  split in.
- **Filters the toolbar omits.** Tag chips stay, as a second chip row when the
  household has tags. The member select goes; Scope takes its place. `?owner=`
  is still parsed, so saved views and API links keep working.
- **`/subscriptions/{id}/money` stays** as the read-only view (what the list
  opens for a row the viewer cannot edit) and keeps the usage card. The editors
  that move into the edit form — split, schedule a price change, attachments —
  are drawn there only for readers; a writer gets an "Edit" link. Every
  existing POST route stays.
- The split is saved with the form, in one transaction with the subscription
  (`SplitService::update` composed inside the service). The API's
  create/update input does not change.
- The **density** toggle writes the existing profile preference (a CSRF POST
  that returns to the list); no new mechanism.
- The **bulk bar** is reinstated over the existing endpoint and service.
- The **full ISO currency list** comes from ICU (the intl extension already
  used for formatting); no new dependency.
- `is_active` stays in the form under More details.

## Status

- [x] Stats strip (per-currency)
- [x] Toolbar: search, category chips, status (incl. Cancelled), scope, saved
      views menu, density, export, summary
- [x] Table + mobile cards; actions gated by `mayWriteRow`
- [x] Bulk selection and bar restyled
- [x] Cancel-by card beneath the list
- [x] Form: always-shown fields, More details, edit-only sections; server-side
      conversion note; custom shares; custom cycle; type; converts-to
- [x] Quick-add modal and full page render the same template
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check` green on both engines (2433 tests on
      PostgreSQL 16 and MySQL 8.4)

### Notes from the build

- **Currencies** are a list of the ISO 4217 codes in circulation
  (`Currency::all()`), not ICU's table: ICU also carries every withdrawn
  currency and PHP cannot ask it which are still tendered. A row in a code no
  longer on the list keeps it as an extra option.
- **Density toggle**: the current option is styled from the root's
  `data-density`, not marked in the buttons, so the two densities still render
  identical markup (`PersonalisationTest`).
- **Remind me** posts `reminder_mode` + `reminder_day[]`, which
  `SubscriptionFormService` maps onto `reminder_days`; "chosen days" with none
  ticked is refused rather than read as the defaults.
- **The price history's classes** were renamed `price-timeline*`: Phase 21's
  household "next 30 days" card reused `.timeline`, positioned absolutely, which
  had laid the cost page's price history on top of itself.
- The top bar's subtitle now shrinks before the title, and page actions keep
  their words on one line.
- The strip's Active tile excludes trials (it is the Active filter's count); the
  dashboard's Active tile, unchanged, still includes them.

## Definition of done

The list and form match the prototype's arrangement with every existing
capability intact — saved views, density, bulk actions, export, tags, notice
period, attachments, scheduled prices, custom splits and cycles, one-off and
lifetime types; filters work with and without script; actions appear only where
usable and are refused where not; all palettes × themes, wide and narrow; gates
green on both engines. Then update `PHASE.md` to the next phase.

## Tests

- The htmx fragment's outermost element is still `#subscription-list`; the filter
  form swaps it whole.
- Status filter returns exactly Active / Trial / Paused / Cancelled rows per the
  Phase 20 derivation.
- "Mine" includes split rows and excludes others'; private rows never appear for
  non-payers.
- Per month excludes one-off, lifetime, paused and cancelled rows and follows the
  per-currency rule.
- Form: a custom split's shares sum exactly; "Only me" with a split is rejected;
  `reminder_days` round-trips all three states; custom cycle and type persist;
  the conversion note is rendered server-side for a non-base currency.
- Quick-add and the full page render identical fields.
- Action controls: a Viewer sees none and gets 403 on each endpoint; a
  Contributor sees them only on rows they own.
- Density: both settings produce identical markup apart from the class.
- `AccessibilityTest` passes on the list, the form page and the modal.