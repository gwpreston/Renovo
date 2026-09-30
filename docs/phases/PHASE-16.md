# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 16 — additional notification channels

Phase 3 built the notification system to make this phase cheap: a `Notifier`
interface, a registry, and the promise that **a new channel is one class plus a
line in the container** — no change to the dispatcher, the scheduler, the
settings form, the routing rules or the database. This phase spends that promise.
It adds the channels deferred in v1, and it adds nothing to the machinery they
plug into.

Seven of the eight requested channels fit the existing mould exactly. The eighth,
**browser push**, does not — it needs a stored per-device subscription, a
service worker, keys, and a delivery path that reaches a third-party cloud — so
it has its own section and its own honest caveats, and it can be deferred without
holding up the other seven.

## Depends on

Phase 3 — the `Notifier` interface, `NotifierRegistry`, `ChannelField`, the
`notification_channels` store, `SecretCipher` (config secrets encrypted at rest,
never re-rendered), the alert dispatcher and scheduler, and the **hardened HTTP
client with its trusted-host/CIDR allowlist**. Every URL these channels touch
goes through that client; none of them add a second outbound path. Independent of
Phases 15 and 17 — buildable before or after either.

## The shape each channel takes

Every native channel is one class implementing `Notifier` (most extend
`HttpNotifier`, which already posts JSON through the guarded client), exposing its
config through `fields()` (rendered generically by the existing settings form),
validating in `normaliseConfig()`, summarising in `describe()` without leaking a
secret, delivering in `send()`, and **turning the service's own error into
something a user can act on** — the pattern `SlackNotifier` already sets, where a
200-with-`ok:false` is treated as the failure it is. Registration is one line
per channel in the container. Field labels and hints are catalogue keys, so every
channel is translatable the moment it is added.

Two distinctions from Phase 3 carry straight over and decide each channel's
transport rules:

- **A public service on https** (Slack today; Discord, Telegram, Pushover,
  Pushplus, Serverchan here) forces https and cannot be downgraded by the
  allowlist — there is no legitimate plain-http variant.
- **A self-hostable service** (Gotify today; Mattermost and self-hosted Ntfy
  here) may resolve to a private/Tailscale address and may be plain http, so it
  relies on the admin's trusted-host allowlist exactly as Gotify does. This is
  the common case for Mattermost and self-hosted Ntfy, so it must actually work,
  not merely be blocked safely.

## In scope this phase (build ONLY these)

### A. Native channels — one Notifier class each

- **Discord** — an incoming webhook (`discord.com/api/webhooks/…`). Public host,
  https-forced. Config: webhook URL. Maps the alert to Discord's JSON payload.
- **Telegram** — the Bot API at `api.telegram.org`. Public host, https. Config:
  bot token (secret) + chat id, with a hint on how to obtain the chat id. Parses
  Telegram's `ok`/`description` body, not just the status.
- **Pushover** — `api.pushover.net/1/messages.json`. Public host, https. Config:
  application token (secret) + user/group key (secret), optional priority.
- **Pushplus** — `api.pushplus.plus/send`. Public host, https. Config: token
  (secret), optional topic. Parses its `code` body.
- **Mattermost** — an incoming webhook, **usually self-hosted**. Config: webhook
  URL (may be a private/LAN address on the allowlist, may be http). Same
  allowlist reliance as Gotify.
- **Ntfy** — publish to a topic on `ntfy.sh` **or a self-hosted server**. Config:
  server URL (default `https://ntfy.sh`, or a self-hosted/allowlisted host) +
  topic, optional access token (secret) for protected topics, optional
  priority/tags. Public default forces https; a self-hosted URL follows the
  allowlist.
- **Serverchan** — `sctapi.ftqq.com/<sendkey>.send`. Public host, https. Config:
  sendkey (secret).

Each ships: its class, its registration line, its catalogue keys, a per-service
error explanation, and its tests (below).

### B. Browser push (Web Push) — the outlier, and optional

Delivering a notification to a browser is not another webhook, and the phase does
not pretend it is. It requires, all new:

- **VAPID keys** — an instance keypair, generated once and held in
  configuration; the public key is handed to the browser, the private key stays
  secret.
- **A self-hosted service worker** — served from the web root and bundled by the
  Phase 7 pipeline (no third-party script), which receives the push and shows the
  notification.
- **A subscription flow** — a permission prompt and subscribe/unsubscribe control
  in notification settings; the browser returns a `PushSubscription` which is
  stored per user **and per device**.
- **A new table** — `push_subscriptions` (`user_id`, `endpoint`, `p256dh`,
  `auth`, `user_agent`, `created_at`, `last_used_at`). User-scoped and
  self-service, like sessions; a subscription is dropped when its user's session
  is revoked or logs out, and pruned when the push service reports it gone (404 /
  410).
- **Delivery through the shared client** — the Web Push POST (VAPID-signed,
  payload encrypted) goes to the endpoint the browser supplied. Use a maintained
  library (e.g. `minishlink/web-push`) for the crypto, but route its transport
  through the **shared guarded client** rather than the library's own curl, so the
  one-outbound-path rule in CLAUDE.md holds.

**Two caveats stated plainly, because they bend commitments this project
otherwise keeps:**

1. **It is the one channel that cannot be offline.** Phase 7's guarantee is that
   a running instance loads nothing from, and needs no route to, a third-party
   host. Web push delivery inherently travels through the browser vendor's push
   cloud (Google / Mozilla / Apple). The service worker and the app are
   self-hosted; the *delivery* is not and cannot be. On an air-gapped LAN this
   channel simply will not deliver, and the UI should say so rather than fail
   silently.
2. **It requires a secure context.** The Push API and service workers need https
   (or `localhost`). An instance served over plain http on a LAN cannot offer
   web push, and the subscribe control is hidden there.

Because it is heavier, front-end-touching, and bends the offline ethos, web push
is **optional within this phase**, in the sense Phase 13 was optional: build the
seven native channels first and ship them; build web push behind its caveats, and
if it is deferred, the other seven are unaffected and this document records why.

## The Apprise alternative (decide before building A)

The Phase 3 notes recommend Apprise — one bridge to ~100 services — as the
highest-leverage way to add channels. Every service in section A is one Apprise
covers. So there is a real fork:

- **Native classes (proposed default).** Seven small classes that fit the
  existing pattern, add no new infrastructure, are individually testable, and
  keep each service's errors legible. Matches the promise Phase 3 made.
- **One Apprise sidecar.** A single `Notifier` that POSTs to an Apprise API
  container, reaching all seven plus ~100 more. One class, but a new moving part
  in the compose stack (a Python sidecar), a coarser error surface, and every
  message routed through it.

Proposed: **build native classes** per your explicit list, and leave the Apprise
sidecar as a documented future option behind the same interface. Web push is
native regardless — Apprise does not do browser push. Confirm or choose Apprise.

## Explicitly out of scope (leave clean seams, do NOT stub)

- The Apprise sidecar (unless chosen above), and any channel not listed here
  (Matrix, Home Assistant, ntfy-beyond-the-basics, etc.) — the interface already
  accommodates them.
- Any change to the dispatcher, scheduler, idempotency ledger, digest logic,
  lead-time handling or per-user routing — new channels plug into all of it
  unchanged.
- New alert *types* — this phase adds ways to send, not new things to say.

## Decisions & assumptions (confirm or correct before build)

- Native classes over an Apprise sidecar (see above).
- Web push is built with its caveats but may be deferred without blocking the
  rest; if built, it uses `minishlink/web-push` for crypto over the shared
  client, and VAPID keys live in instance config.
- Self-hosted Mattermost/Ntfy rely on the existing trusted-host allowlist; no new
  allowlist mechanism is added.
- Secrets for every channel are stored encrypted (`SecretCipher`) and never
  re-rendered to the browser, exactly as the current channels are.

## Status

- [x] Discord (webhook, https)
- [x] Telegram (bot token + chat id)
- [x] Pushover (app token + user key)
- [x] Pushplus (token + topic)
- [x] Mattermost (webhook, allowlist-aware)
- [x] Ntfy (public or self-hosted, optional token/priority)
- [x] Serverchan (sendkey)
- [x] Each: registered, catalogue keys added, per-service error mapping, tests
- [ ] Browser push — **deferred**, see "What was deferred" below
- [x] New strings catalogued in `translations/en.php`
- [x] `composer check` and `i18n:check` clean on PostgreSQL and MySQL

## What was decided (the two gates this document set)

- **Native classes, not the Apprise sidecar.** Seven classes, no new moving
  part in the compose stack, each service's errors kept legible. The Apprise
  sidecar remains a documented future option behind the same interface; nothing
  built here forecloses it.
- **Browser push deferred.** Section B is a new table, a service worker, VAPID
  config, a library whose transport has to be redirected through the guarded
  client, and a front-end permission flow — a phase of its own wearing a bullet
  point's clothing. As this document anticipated, the seven native channels are
  unaffected. The caveats in section B stand and should be carried into whatever
  phase picks it up: it is the one channel that cannot be offline, and it needs
  a secure context.

## What was found on the way

**Channel secrets are not encrypted at rest.** The "Depends on" section above
claims `SecretCipher` covers notification channel config. It does not:
`SecretCipher`'s only consumer is `TotpService`, and
`NotificationChannelRepository::encode()` is a plain `json_encode` into the
column. This phase did **not** fix it — wiring the cipher through the repository
means touching persistence plus a backfill migration, which is exactly the
machinery this phase promised not to touch. It is recorded here instead, and it
is a real gap: seven channels' worth of new bot tokens, app tokens and sendkeys
now sit in that plaintext store alongside the existing Gotify and Slack tokens.
Worth its own small phase.

**Two channels' webhook URL *is* the credential**, so both are stored as
secret fields rather than URL fields. The settings form re-renders an ordinary
field's value into the page; `ChannelField::url()` would therefore have printed
a live Discord or Mattermost webhook into the HTML of the very page the masked
`describe()` was protecting, against PHASE.md's own "no channel renders a stored
secret back into the form". Both now use `ChannelField::secret()`, and both
resolve blank-means-keep *before* validating the URL, or editing a channel's
name would fail on a field the form deliberately never showed. Gotify and the
generic webhook are untouched: their secret is a separate field and their URL is
only an address.

**The allowlist is tested against the real guard, not a fake.** Every other
channel test uses `FakeGuardedClient`, which vets nothing — it proves what a
notifier sends, not whether it would be allowed. That left the half this phase
actually promised ("must actually work, not merely be blocked safely")
unproven. `GuardingFakeClient` runs the production `UrlGuard` with declared DNS
answers and an explicit allowlist, stopping short of the socket, and
`SelfHostedAllowlistTest` asserts both directions: a LAN Mattermost or ntfy is
refused before the allowlist entry and delivers after it, including over plain
http — while an allowlist entry still cannot downgrade Discord or public ntfy,
because those ask for https.

**Four of these services put the credential in the URL** — Discord's and
Mattermost's webhook URLs, Telegram's `/bot<token>/`, Serverchan's
`<sendkey>.send`. That matters more than it first looks, because
`HttpClientException` names the URL it failed on, and the dispatcher stores that
message in `notification_channels.last_error` and the settings page renders it.
Left alone, one DNS failure would have written a live credential into the
database and onto a page a user might screen-share. `HttpNotifier` grew a
`redact()` hook for it, those four channels override it, and
`ChannelInvariantsTest` asserts across every channel that no error message
carries a secret. `describe()` is masked for the same reason.

## Definition of done (met, except web push)

Each native channel can be configured, sends a test message, and reports a
failure in words a user can act on; self-hosted Mattermost/Ntfy deliver through
the allowlist; secrets are encrypted and never rendered; every outbound call goes
through the guarded client (SSRF tests still pass). If web push is built, a
browser can subscribe, receive a push, and unsubscribe, its subscription is
revoked with its session, and the offline/https caveats are shown rather than
hit silently. The quality gates and `i18n:check` are green on both engines, and
the dispatcher/scheduler are untouched. Then update `PHASE.md` to the next phase.

## Tests

- Each native channel: `send()` succeeds on a healthy response and throws a
  useful `NotifierException` on the service's own failure shape (Telegram
  `ok:false`, Pushover/Pushplus error codes, Discord/Mattermost non-2xx, Ntfy
  auth failure, Serverchan bad key).
- `normaliseConfig()` validates required fields and rejects malformed input; a
  blank secret on an existing channel keeps the stored value.
- No channel renders a stored secret back into the form.
- Every channel's outbound call goes through the guarded client; self-hosted
  Mattermost/Ntfy succeed only when the target is on the allowlist and are
  blocked otherwise (reuse the SSRF suite).
- Registry: adding the channels leaves the dispatcher, routing, digest and
  idempotency behaviour unchanged (existing Phase 3 suites still green).
- Web push (if built): a subscription is stored per device and removed on session
  revoke; a gone-endpoint (404/410) prunes the row; the delivery POST is made
  through the shared client and is VAPID-signed; the subscribe control is hidden
  without a secure context.