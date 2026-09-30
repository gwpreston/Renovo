# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 3 — Notifications, reminders & the hardened HTTP client.**

Goal: proactive, idempotent notifications across multiple channels, on a real
scheduler, for every alert type the app now understands.

## Depends on
Phases 1–2 — the events to notify about (renewals, trials, cancel-by, budgets),
the shared HTTP client, per-user settings surfaces.

## In scope this phase (build ONLY these)
- Harden the shared HTTP client for user-supplied URLs: https-only where
  applicable; reject private/loopback/link-local/reserved IPs (v4 + v6); pin the
  resolved IP against DNS-rebinding; enforce timeouts, max response size, no
  redirects to disallowed targets; per-user/subscription outbound rate limit.
- Admin-configurable trusted-host/CIDR **allowlist** overriding the private-IP
  block (fixes Tailscale-IP Gotify/OIDC); opt-in and logged.
- Notifier interface + registry (a new channel = one class + registration).
- Channels built now, fully working: **Email (SMTP), Gotify, Slack (post message to channel or user),
  generic Webhook**. Gotify/Slack/Webhook go through the hardened
  client; SMTP is exempt (not a URL fetch).
- Alert types, all reusing one dispatcher: upcoming renewal · trial about to
  convert · cancel-by deadline (from notice-period) · budget projected to exceed
  (reuse Phase 2 projection; at most one alert per budget per period per
  crossing; re-arm only after projection drops back under).
- Timing/prefs: multiple reminders per subscription (e.g. 30/7/1 days); digest
  mode (weekly/monthly summary); per-user preferences (channels, per-channel
  config, lead times, which alert types route where).
- Scheduler command (e.g. `bin/console reminders:run`): finds due items,
  dispatches idempotently (records what was sent), re-evaluates budgets. Wire the
  Docker scheduler container to run it daily; document a cron entry.
- Extend the first-run wizard: configure at least one channel + SMTP, with a
  "send test message" step.

## Explicitly OUT of scope until a later phase (leave seams, do NOT stub)
- Apprise bridge / extra channels beyond the four above → later extension
- JSON API / OpenAPI / import-export → **Phase 5**
- Passkeys / OIDC / audit log → **Phase 4**
- i18n / calendar view / metrics → **Phase 6**

## Status
- [x] HTTP client hardened (SSRF: range checks, DNS-rebind pinning, redirects)
- [x] Admin trusted-host/CIDR allowlist (opt-in, logged)
- [x] Notifier interface + registry
- [x] Channels: Email, Gotify, Slack, Webhook (tested)
- [x] Alert types: renewal, trial-convert, cancel-by, budget-exceeded
- [x] Multiple lead times + digest mode
- [x] Per-user notification preferences
- [x] Scheduler command (idempotent) + Docker wiring + cron docs
- [x] Wizard: channel + SMTP + test-send
- [x] Tests: idempotency (no double-send), each alert fires correctly + routing,
      budget de-dup/re-arm, SSRF rejects private + honours allowlist + proxy,
      digest aggregation — all passing
- [x] Readme: Update "Built in phases" section with current phase

## Decisions & deviations made during the build

**Mailer.** No new library. `symfony/mailer` was already wired for verification
and reset mail in Phase 1, and `MailerService` is reused unchanged; the email
channel is a `Notifier` wrapping it. SMTP stays exempt from the SSRF client, and
the reason is worth stating rather than inferring: the guard exists because a
user can type a URL and make the server fetch it, and nobody types the SMTP
host.

**Allowlist format.** One text column, four accepted shapes: a host
(`gotify.lan`), a dotted suffix (`.lan`, matching subdomains), a bare address,
or a CIDR block (`100.64.0.0/10`). Host entries match names, address entries
match resolved addresses; the two never cross. A pattern the guard could not
parse is refused at the form rather than stored and silently ignored — a rule an
operator believes is in force but is not is worse than no rule. An allowlisted
host may also be reached over plain `http`, because a LAN service usually has no
certificate; `httpsOnly` (which Slack passes) overrides even that.

**Idempotency ledger.** `notification_log`, claimed by INSERT rather than by
SELECT-then-INSERT, with a unique index on
`(user_id, alert_type, subject_type, subject_id, occurrence_key, channel_id)`.
Two overlapping scheduler runs both attempt the insert and the database decides;
one gets the row and the other gets a collision. The occurrence key is the
**charge date plus the lead time** (`2026-10-01:7`), which makes a re-run silent,
a rescheduled payment a genuinely new alert, and 30/7/1 three reminders rather
than one. `subject_id` is `0` rather than null when an alert has no single
subject, because PostgreSQL treats nulls in a unique index as distinct and a
nullable column would permit duplicates on one engine only. Status is
`pending → sent | failed`: only `sent` suppresses a later attempt, so a
transient failure stays retryable up to `NOTIFY_MAX_ATTEMPTS`.

**Lead-time matching is "at most this many days left", not "exactly".** The
tightest configured lead that still covers the remaining days is the one that
fires. A scheduler that misses a day — a reboot, a full disk — therefore still
sends the seven-day warning when it next runs, instead of skipping it silently
for ever. Tested.

**Per-subscription reminder override.** `subscriptions.reminder_days`, null
meaning "use my preference" and an empty string meaning "never remind me about
this one". The two are deliberately different values; collapsing them would make
it impossible to silence one subscription without silencing everything.

**Per-user channel secrets live in the database.** Non-negotiable 7 is read as
governing *instance* secrets: those stay in the environment, SMTP included, and
the wizard does not collect them. A Gotify or Slack token belongs to a person,
not to the instance, so it has nowhere else to live — the precedent is
`KEY_RATE_PROVIDER_KEY` from Phase 2. Such a value is never rendered back into a
form (blank means unchanged) and never written to a log.

**The wizard does not collect SMTP.** "Configure at least one channel + SMTP" is
implemented as: step two *reports* the relay the instance will use, with the
environment variables that set it, and offers a test send that proves it works.
Collecting a host and password into the database would contradict the rule
above.

**Budget alerts are a crossing, not a threshold.** `BudgetPeriod` windows roll
from today, so there is no calendar boundary for "one alert per period" to reset
on; `budget_alert_state` is the state machine instead — fire on the transition
to over, re-arm only on the transition back under. A projection of `null`
(spend in a currency with no rate to the budget's) is neither: treating it as
"under" would re-arm a budget that may well still be over. The warning threshold
does **not** alert; the phase asks for "projected to exceed".

**For digest users the budget state machine runs on digest days only.** A
narrowing of "the scheduler re-evaluates budgets", and deliberate: evaluating
daily would flip the state to breached on a silent day, and the crossing would
then never be reported at all. Nothing is queued between digests, so there is
nothing to lose.

**Two client types, not one interface.** `GuardedClient` is a different type
from PSR-18's `ClientInterface`, and `HttpClient` does not implement it. Had the
guarded path been a `ClientInterface`, a container definition could have handed
a notifier the unguarded client with nothing looking wrong. This way the
substitution cannot be expressed.

**The outbound rate limit is enforced at the dispatcher, not inside the HTTP
client.** The limit is per user and per subscription, and the HTTP client has no
idea what a user is. It counts ledger rows, which already record one row per
delivery attempt, rather than keeping a second tally that could drift. It
applies to the **Send test** button as well as to scheduled alerts: that is the
one path where a person rather than the scheduler decides when a request leaves
the server, and its ledger key is deliberately loose so that testing twice
works.

## Known limitations
- A digest whose day is missed entirely — the scheduler down for that whole day
  — is skipped rather than deferred, because its ledger key is the ISO week or
  month. The immediate path has no such gap. Left as-is deliberately: deferring
  would mean storing a pending-digest state that nothing else needs.
- Reminders fire on UTC dates. Per-user time zones are not part of this phase.

## Definition of done
App runs, scheduler dispatches once per due item, all Phase 3 tests green, CI
green, no Phase 4+ features stubbed. Then copy PHASE-4.md over PHASE.md and
commit.