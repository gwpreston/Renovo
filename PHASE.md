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

**None.**

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

## Status

- [ ] General: household, rates (existing providers, refresh), categories
      (inline rename), tags, payment methods
- [ ] Data & integrations: import, backup/restore, export, API tokens, recent
      activity, feed link
- [ ] Instance tab (admin only, 403 otherwise)
- [ ] Notifications page: channels, multi lead times, delivery, budget and price
      toggles, full routing matrix
- [ ] Phase 19's interim Settings link row removed; every destination reachable
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

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