# Roadmap

Where Renovo is, and what may come next. This is a list of **candidates, not
scope**: nothing here is built until a phase brief in
[`docs/phases/PHASE.md`](docs/phases/PHASE.md) names it (see `CLAUDE.md`).

## v1 — Phases 1–29

v1 is everything built in Phases 1 to 29: the foundation, money, notifications
and the scheduler, advanced auth, the API and import/backup, i18n and demo mode
(1–6); the build pipeline, design system and first pass over every screen
(7–14); household membership, more notification channels and payment methods
(15–17); the Renovo re-skin (18–28); and the signed-out screens (29). The
[README](README.md#renovo) summarises each phase, and every phase's brief,
decisions and deferrals are archived in [`docs/phases/`](docs/phases/).

v1 is tagged when Phase 29 is signed off.

## After v1

Work after v1 continues as numbered phases (Phase 30, 31, …), each with its own
brief in `docs/phases/PHASE.md`, and releases group finished phases (v1.1,
v1.2, … and v2 for anything that breaks an upgrade path). *This process is a
proposal; adjust it before the first post-v1 phase starts.*

The items below were deferred during v1. Each names where it was deferred.
Only the first is ranked; the rest are unordered until someone picks them up.

### Security

- **Encrypt notification channel secrets at rest.** Channel config (bot tokens,
  app tokens, webhook URLs that are themselves credentials) is stored as plain
  JSON; `SecretCipher` is used only by TOTP. It needs the cipher wired through
  `NotificationChannelRepository` and a backfill migration. *Phase 16 — "Worth
  its own small phase".* **Do this first.**

### Sign-in and accounts

- **OIDC / SSO.** Left out of Phase 4 by decision; the largest missing piece
  for anyone running Renovo beside other self-hosted services. It would use the
  shared HTTP client for discovery and the trusted-host allowlist for private
  providers. *Phases 4, 6.*
- **Name the inviter on an invitation.** Nothing records who sent an invite,
  so the page says only "You have been invited to join {household}". A
  data-model change. *Phase 29.*

### Notifications

- **Browser push (Web Push).** It needs a per-device subscription table, a
  service worker, VAPID config, a transport redirected through the guarded
  client and a front-end permission flow. The caveats go with it: delivery goes
  through the browser vendor's push service, so it is the one channel that
  cannot work offline, and it needs a secure context. *Phases 16, 28.*
- **An Apprise bridge.** It would add every channel Apprise supports behind the
  existing `Notifier` interface, without writing another class per service.
  *Phases 6, 16.*
- **Per-type lead times.** One set of lead times covers every dated alert
  today. The chips' markup (`lead_types`) already names the types. *Phase 28.*

### Money and data

- **"Spend removed by cancellations"** as a figure (the design's "Total
  Savings"). `cancelled_at` makes it computable. *Phases 8, 20.*
- **A `paused_at` date.** Pausing is a flag, so past spend can't tell when a
  pause began. *Phase 20.*
- **Category groups.** Categories are flat by decision. *Phase 20.*
- **Rounding** of displayed figures. *Phase 28.*
- **More exchange-rate providers.** *Phase 28.*
- **Backup history**: a record of when backups were taken. *Phase 28.*

### Screens

- **A dashboard insight tile.** The insights are on Analytics only, so the
  feature can be judged on one screen before it goes on two. *Phases 13, 23.*
- **Page the calendar backwards.** There is no ledger of past charges to page
  into. *Phase 6.*

### Accessibility and languages

- **A real screen-reader pass.** Structure is covered by `AccessibilityTest`.
  Reading order and control names out of context need a person with a screen
  reader. *Phase 6.*
- **A first translated locale.** The machinery and `i18n:check` are in place,
  and only `en` ships. A real translation is also the only real test of the
  catalogue's keys. *Phase 6.*

## Not planned

These were considered and ruled out. They are not a backlog.

- **Bank / transaction sync** (Plaid, GoCardless, Firefly): out of v1 by the
  spec, and not planned.
- **A single-page client**, CSS-in-JS, a component-framework runtime or an
  offline service worker for the app. Renovo is server-rendered by design.
  *Phases 7, 8.*
- **Anything the browser loads from a third-party host.** The offline rule is
  permanent (`CLAUDE.md`, non-negotiable 8).
- **Per-path HTTP metrics.** The counter grows without limit under a scan;
  status classes answer the question. *Phase 6.*
- **Translating stored notes and audit-log detail.** These are rows and
  machine data, not interface copy. *Phase 6.*
- **A "Keep it" button on trials.** Keeping a trial is what happens when you do
  nothing. *Phase 21.*
