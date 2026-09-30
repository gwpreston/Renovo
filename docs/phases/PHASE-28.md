# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 28 — settings and notifications

The last screens of the re-skin. **Settings** becomes a tabbed page for the
household (and, for an instance administrator, the instance). **Notifications**
becomes its own per-user page (decision 13) — the prototype put it under
household Settings, but each person's channels and reminders are their own. With
this phase the interim link row Phase 19 added to Settings is removed, because
everything it pointed at now has a place in a tab.

## Depends on

- **Phases 2, 5, 17** — rate providers and cache, categories, tags, payment
  methods, import, backup/restore, export, API tokens.
- **Phases 3, 16, 20** — channels (all eleven), lead times, digests, routing, the
  price-change alert.
- **Phase 4** — the audit log; **Phase 6** — demo mode, metrics, health.

## In scope this phase (build ONLY these)

### A. Settings — General tab

- **Household**: household name; base currency (the full ISO list, with the
  note "Totals, budgets and forecasts are shown in this currency"). **Save
  household**. No rounding option (decision 14).
- **Exchange rates**: provider — the existing three, Frankfurter (ECB) default,
  exchangerate.host, Fixer (decision 11) — with the key field where one applies
  (environment key wins, as now); a table of pair · rate · "Last refreshed {date,
  time}"; **Refresh now**, which runs the existing refresh through the shared
  client and respects the one-hour failure back-off.
- **Categories**: chips with each category's subscription count; **rename
  inline**, delete (unassigns), add. Gated by `category.manage`.
- **Tags**: the same pattern (the prototype has no tag section; tags need one).
- **Payment methods**: Phase 17's management, as a section here.

### B. Settings — Data & integrations tab

- **Import**: "Drop a CSV or JSON file — you map the columns and preview every
  row before anything is saved", into the existing wizard.
- **Backup & restore**: what the file contains (per Phase 20's decision on
  private rows), **Download backup**, **Restore from file**. The prototype's
  "Last backup · size" line is not shown — the application does not record
  backups, and a figure it cannot source is not printed.
- **Export**: CSV / JSON of subscriptions.
- **API tokens**: each with name, ability (Read only / Read & write — already
  built), last used; **New token** (shown once); Revoke.
- **Recent activity**: the five latest household audit entries, and a link to the
  full audit log.
- **Calendar feed**: a link to the Calendar's feed card.

### C. Settings — Instance tab (instance administrators only)

Public registration on/off; isolation mode (the one place it is changed —
decision 3); the trusted-host/CIDR allowlist; demo mode; read-only status of
SMTP (from the environment), metrics and the scheduler's last run. Hidden and 403
for everyone else.

Tabs are links (`/settings`, `/settings/data`, `/settings/instance`), so each
works without script and the rail's active item stays Settings.

### D. Notifications (per user)

At its existing route, rebuilt:

- **Channels**: every configured channel — Email and any of the ten others —
  with its masked description, on/off, **Send test** and edit; **Add a channel**
  listing all eleven types. Secrets are never rendered back (Phase 16's rule).
- **When to remind you**: for renewals, trial conversions and cancel-by
  deadlines, **one or more** lead times each (1, 3, 7, 14, 30 days) — the
  application's multiple lead times, not the prototype's single choice
  (decision 12).
- **Delivery**: Immediate / Weekly digest / Monthly digest (decision 12).
- **Budget alerts**: on/off — "when a budget is projected over".
- **Price changes**: on/off — Phase 20's alert.
- **Routing**: a matrix of alert type × **every** configured channel (the
  prototype omitted Webhook); channels that are off are shown disabled.

## Data-model changes

~~None.~~ **One column**, agreed before the build: `notification_preferences.
budget_alerts` (boolean, default true), the budget switch's storage — the
price-change switch already had its column and the budget one had none.
Migration `20261201000001`, with a `down()` that drops it.

## Explicitly out of scope

- Rounding (decision 14); new rate providers (decision 11).
- Recording backup history.
- Browser push (still deferred from Phase 16).

## Decisions & assumptions (confirm or correct before build)

- Settings has three tabs — General, Data & integrations, Instance (admins only);
  Notifications is its own per-user page.
- The instance tab is where isolation is changed.
- Tags get a section beside categories.
- The "Last backup" line is not shown.

Settled with the user before the build:

- **One set of lead times, not one per alert type.** `lead_days` is one list, so
  the chips (1, 3, 7, 14, 30) apply to renewals, trial conversions and cancel-by
  deadlines alike — decision 12's "multiple lead times", without a migration.
  A stored value outside the five (from the old free-text field) is drawn as a
  chip of its own, so saving the page keeps it.
- **Budget alerts get a column** (above). Off drops the alert and nothing else:
  the budget's armed/breached state is still evaluated, so turning it back on
  does not announce an old crossing as new.
- **The base currency and the rate provider stay the instance's.** They are
  drawn on General, where a reader looks for them, but only an instance
  administrator can change them (`POST /settings/currency`, `POST
  /settings/rates/refresh`, both `instance.manage`); everybody else is told what
  they are and who sets them.

Made during the build:

- **Refresh now is refused only while a *failed* attempt is inside its retry
  window** (`ExchangeRateService::retryAfter()`: the last attempt is newer than
  the last table). A successful refresh does not hold the button back.
  Changing the base or the provider drops the table *and* the attempt marker
  (`invalidate()`), since a back-off belongs to the provider it was earned
  against — otherwise the success just before the change would read as a
  failure and lock the button out for an hour.
- **The rate table lists the currencies the household's subscriptions are in**,
  as "1 EUR → GBP", to four places; the rest of the provider's table is counted.
- **Recent activity is the head of the log the "Full audit log" link opens** —
  the same `AuditLogService::page()` read — so an instance administrator sees
  the instance's latest, as `/audit` shows them.
- **Export gained JSON** (`/subscriptions/export.json`): the CSV's rows under the
  CSV's headings. Both files round-trip through the importer's automatic preset
  (`ImportTest::testTheListExportIsReadBackByTheAutomaticPreset`).
- **A new or reissued token lands on `#new-token`**, the callout holding its
  one-time secret at the top of the tab, not on the tokens card below it.
- **`InstanceAdminService::apply()` leaves a switch alone when it is not
  posted.** The instance settings are on two tabs now, and each form posts only
  its own; absent used to mean "off".
- **A channel's switch is its own route** (`…/channels/{id}/active`) that
  rewrites the stored label and configuration unchanged, so turning a channel
  off never round-trips a secret through a form.
- **Routing shows what is delivered.** The old grid drew a channel with no rows
  as all-ticked even when routing was saved and delivery treated it as muted.
  The grid now ticks exactly what `channelsFor()` delivers, and a channel added
  after routing was saved is given every alert type, so adding one still works.
  A channel that is off is drawn disabled; its choices ride in hidden fields.
- **The old screens' paths redirect** (302) to their sections: `/categories` →
  `/settings#categories`, `/payment-methods` → `/settings#payment-methods`,
  `/settings/backup` → `/settings/data#backup`, `/settings/api-tokens` →
  `/settings/data#api-tokens`. Every write kept its path and returns to its
  section; a failed category, tag or payment-method form redraws General with
  the error beside its row.

## Status

- [x] General: household, rates (existing providers, refresh), categories
      (inline rename), tags, payment methods
- [x] Data & integrations: import, backup/restore, export, API tokens, recent
      activity, feed link
- [x] Instance tab (admin only, 403 otherwise)
- [x] Notifications page: channels, multi lead times, delivery, budget and price
      toggles, full routing matrix
- [x] Phase 19's interim Settings link row removed; every destination reachable
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check` green on both engines

## Definition of done

Every household, instance and per-user setting has a place in the new layout;
nothing reachable before is unreachable now; permissions gate each tab and
section server-side; no secret is rendered; all palettes × themes, wide and
narrow; gates green on both engines. The re-skin that began in Phase 18 is
complete — record in this file anything left for later, and update CLAUDE.md's
"Current phase".

## Tests

- Instance tab: 403 for every non-admin role; visible to an instance admin.
- Category inline rename persists; delete unassigns without deleting
  subscriptions; a Viewer cannot manage either (403).
- Refresh now respects the failure back-off and goes through the shared client.
- Lead times: several per alert type persist and each fires (Phase 3's suite).
- Routing matrix includes every configured channel type, including Webhook.
- No channel secret appears in the rendered page.
- Every route the Phase 19 link row pointed to is reachable from a tab.
- `AccessibilityTest` passes on every tab and the Notifications page.

## Left for later

The re-skin is complete. Nothing on these screens is deferred beyond what the
phase already ruled out (rounding, new rate providers, backup history, browser
push). Two things noticed and left alone:

- Lead times are one set for every dated alert. Per-type lead times would need
  a column per type; the chips' markup (`lead_types`) already names the types,
  so the seam is there if it is ever wanted.
- Clearing every box in the routing grid still means "everything everywhere",
  as it always has (no rows is the default). The hint says so. Silencing an
  alert type entirely is done with its switch (budgets, price changes), with no
  lead times (the dated alerts), or by turning channels off.
