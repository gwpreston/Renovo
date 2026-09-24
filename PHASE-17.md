# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 17 — payment methods

A subscription is paid *with* something — a card, PayPal, a direct debit — and
the application does not record which. This phase adds that: a managed list of
payment methods (a sensible default set, plus the household's own additions with
logos), an assignment on each subscription, and the method surfaced everywhere a
subscription's shape is shown, including a new breakdown so "what am I paying
through each method" reads as easily as spend by category does.

It is a new **attribute**, not a new kind of money. A payment method is a label
with a logo; it holds no amount, moves no money, and charges nothing. Renovo
tracks what is due, not a ledger of what was paid, and a payment method does not
change that — it groups what is due by how it will be paid.

## Depends on

- **P1** — the subscription model and the central scoping layer; **categories**,
  whose pattern this mirrors almost exactly.
- **P2 / P12** — the statistics and the category breakdown/donut this extends.
- **P5** — the API (a new editable field must be carried), backup/restore
  (fidelity), and the file-upload/validation used for logos.
- **P7–P8** — the asset pipeline (icons) and the design tokens (chart colours).

Mirroring categories is the through-line of the whole phase: a payment method is
household-scoped metadata visible to the whole household even in ISOLATED mode
(like a category name, it carries no financial information), assigned to a
subscription through a nullable foreign key, and it feeds the same breakdown
machinery. Where a decision has already been made for categories, this phase
makes the same one for payment methods rather than inventing a second answer.

## In scope this phase (build ONLY these)

### The model

- **`payment_methods` table**, household-scoped, mirroring `categories`: `id`,
  `household_id`, `name`, `logo_path` (nullable), `colour` (nullable, for the
  breakdown's segments), `created_at`, `updated_at`, unique `(household_id,
  name)`, FK on `household_id`. No owner column — household-wide metadata, as
  categories are.
- **`subscriptions.payment_method_id`** (nullable) — a foreign key to
  `payment_methods`, **`ON DELETE SET NULL`**, indexed, mirroring `category_id`
  exactly. Removing a method unassigns it from its subscriptions; it never
  deletes a subscription.

### The default list (seeded, generic icons — not brand logos)

A default set is seeded when a household is created (in the same two places a
household is made today: the setup wizard and registration), so a new instance
has methods to choose from immediately — e.g. **Credit Card, Debit Card, Direct
Debit, Bank Transfer, Standing Order, PayPal, Cash, Gift Card, App Store, Google
Play**. Two rules on the defaults:

- **Generic icons, not brand marks.** The defaults ship with neutral Lucide icons
  (`credit-card`, `banknote`, `landmark`, `wallet`…), **not** bundled PayPal / Visa
  / Mastercard logos. Those are trademarks, and this project is careful about what
  it vendors (the SIL-licensed font, MIT-licensed icons). A household that wants a
  brand's own logo uploads it themselves.
- **Editable free text after seeding.** The seeded names are resolved through the
  catalogue at creation time (so a non-English instance gets sensible defaults),
  then are ordinary editable rows — rename, recolour, remove, or add to freely.

### Managing the list

A small management screen **under Settings, beside Categories**: create a method
(name + optional colour + optional uploaded logo), rename/recolour, upload or
clear a logo, and delete (which unassigns via the FK, per above). Logo upload
reuses the existing upload validation — type detected from the bytes, size-capped,
app-chosen filename — and the existing logo storage. Managing the list is gated by
the **same permission as managing categories**; a Viewer hitting these endpoints
gets 403 from the permission layer.

### Assigning it

- A **select on the subscription form** (create and edit), showing each method's
  logo and name, with "none" allowed. Changing it is a subscription edit, so it
  follows subscription-edit permissions (Editor+; Viewer 403), and is written
  through the same service and repository as every other field.
- Shown on the **subscription list** and detail as a small logo-and-name badge,
  and wherever a subscription's identity is presented.

### The breakdown (the donut, and its honest degrade)

- Add a **`by_payment_method`** aggregation to the statistics the pages already
  compute, alongside `by_category`.
- Render it with the **existing breakdown machinery** (`CategoryBreakdownService`
  and its donut/bars), as a **spend-by-payment-method donut** on the Analytics
  screen (P12), and as the proportion bars in the category-spending widget's
  neighbour where it fits. Segment colours come from each method's `colour`, with
  a generated fallback.
- It obeys the **per-currency rule identically**: a donut implies one whole, so
  when the methods span currencies that cannot all convert to one base, the
  screen shows per-currency figures rather than a donut whose centre is a number
  that does not exist — the same degrade the category donut already performs.
  One-off/lifetime entries stay out of the recurring figures exactly as elsewhere.

### The edges that must move with it

- **API (P5).** Add `payment_method_id` to the subscription payload, the OpenAPI
  spec, **and** `docs/api.md` — CI's OpenAPI-coverage and API-doc-coverage checks
  fail if any of the three is missing. Add payment methods to the taxonomy
  endpoint for parity with categories/tags.
- **Backup / restore (P5).** Include `payment_methods` and the assignment in the
  export and import so a restore reproduces them; the fidelity test must cover it.
- **Sample data.** Update the demo seeder **and** the in-app demo-mode seed so a
  fresh dev database and the demo instance both have the default methods *and*
  have them assigned across the sample subscriptions — otherwise the new donut has
  nothing to draw.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Any actual payment processing, charging, or storing of card numbers / PANs. A
  payment method is a label; this is not a wallet and stores no credential. (The
  design's virtual-card furniture stays dropped, per Phase 8.)
- Bank / transaction sync — out of v1 entirely, unchanged.
- Per-payment-method budgets or alerts — a possible later idea; leave the seam,
  do not build it.
- CSV import column-mapping for payment method — a clean seam to add later; note
  it, do not build it now unless trivial.

## Decisions & assumptions (confirm or correct before build)

- Defaults are **names + generic icons**, seeded per household; brand logos are
  user-uploaded only (trademark reason above).
- Delete = **unassign** (`ON DELETE SET NULL`), never block and never cascade-
  delete subscriptions.
- Management lives **under Settings beside Categories**; assignment lives on the
  **subscription form**.
- The breakdown lands on **Analytics** (a payment-method donut); adding it to the
  dashboard as well is optional and easy once the aggregation exists — say if you
  want it there too.
- `colour` is stored on the method for the donut; if you would rather derive
  colours from a palette and drop the column, say so.

## Status

- [x] Migration: `payment_methods` table (+ FK, unique index) — plus a nullable
      `icon` column the spec did not list, holding the seeded defaults' generic
      icon key (checked against `DefaultPaymentMethods::ICONS`)
- [x] Migration: `subscriptions.payment_method_id` (nullable, `SET NULL`, index)
- [x] Default set seeded on household creation (wizard + registration, and the
      demo seed), generic icons, catalogue-resolved names; seeded once, never
      topped up. Households that predate the phase get an "Add the default
      list" button on the empty management screen instead of a backfill
- [x] Payment-method management screen (create/rename/recolour/logo/delete),
      permission-gated like categories (`category.manage`) — at
      `/payment-methods`, a nav item after Categories and linked from Settings;
      colour is optional ("Automatic colour" takes the theme palette)
- [x] Logo upload reuses existing validation + storage (`LogoStorage`); old
      files are removed on replace, clear and delete
- [x] Assignment select on the subscription form (create + edit), through the
      existing service/repository — a native select with a logo/icon preview
      beside it
- [x] Method badge shown on the subscription list + detail (list row, edit
      form, cost page)
- [x] `by_payment_method` stat + payment-method donut on Analytics, per-currency
      degrade honoured — the category donut's template became the shared
      `stats/_breakdown.twig`; a method's own colour is used when set
- [x] API: `payment_method_id` in payload + OpenAPI + `docs/api.md`; taxonomy
      endpoint parity (`/api/v1/payment-methods` GET/POST/PUT/DELETE). Unlike
      the older fields, an absent `payment_method_id` on PUT keeps the current
      assignment; only an explicit `null` clears it
- [x] Backup/restore includes methods + assignment (and method logos), merged
      by name on restore; archives without payment methods still restore
- [x] Demo seeder + demo-mode seed updated (methods seeded and assigned), and
      `bin/dev-setup.sh --with-sample-data` assigns them across both members
- [x] New strings catalogued in `translations/en.php`
- [x] `composer check`, `i18n:check`, OpenAPI + API-doc coverage green on both
      engines (PostgreSQL and MySQL 8.4; migrations apply, roll back and
      re-apply on both)

Not yet checked by hand: the screens in a browser (covered by functional and
accessibility tests only), and `bin/dev-setup.sh --with-sample-data` end to end
(its payment-method id lookup was checked against rendered form HTML).

Seams left, per scope: CSV import column mapping, per-method budgets/alerts,
the badge on the dashboard/calendar/cancellations/forecast, and a
payment-method donut on the dashboard.

## Definition of done

`docker compose up` runs clean, the migrations apply and roll back on a fresh DB,
a new household starts with the default methods, a member can add/rename/remove
methods (with logos) and assign one to a subscription, the assignment survives an
API round-trip and a backup/restore, the Analytics screen shows a spend-by-method
donut that degrades to per-currency figures when it must, the demo data shows it
populated, and the quality gates, `i18n:check` and the API-coverage checks are
green on PostgreSQL and MySQL. Then update `PHASE.md` to the next phase.

## Tests

- `payment_methods` CRUD is household-scoped (a Viewer cannot manage; ISOLATED
  does not hide household-wide methods, matching categories).
- Assigning a method to a subscription persists and is returned; clearing it
  writes null.
- Deleting a method **unassigns** its subscriptions (`SET NULL`) and deletes no
  subscription.
- Default methods are seeded exactly once per household on creation and not
  re-seeded.
- `by_payment_method` sums correctly; the donut degrades to per-currency figures
  when currencies cannot all convert, and one-off/lifetime entries are excluded
  from the recurring figures.
- API: `payment_method_id` round-trips through create/edit; OpenAPI-coverage and
  API-doc-coverage tests pass with the new field.
- Backup → restore reproduces methods and assignments faithfully and respects
  isolation.
- Logo upload rejects a disguised or oversized file and stores a safe filename.
- Demo/sample data includes methods and assigns them across subscriptions.