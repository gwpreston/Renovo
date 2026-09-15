# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 4 — Advanced auth & security surface.**

Goal: strengthen and modernise authentication; give admins and users visibility
and control over access.

## Depends on
Phases 1–3 — base auth, roles, the hardened HTTP client (for OIDC discovery),
sessions table.

## In scope this phase (build ONLY these)
- TOTP two-factor as an option on password accounts.
- Passkeys / WebAuthn as a first-class login method AND as a second factor,
  alongside TOTP (use a maintained library, e.g. web-auth/webauthn-lib). Multiple
  passkeys per user; name/revoke them.
- OIDC / SSO login **[include only if wanted — otherwise omit]**: `.well-known`
  discovery + JWKS **through the hardened SSRF client**, respecting the
  trusted-host allowlist; map/link OIDC identities to local users.
- Audit log: login success/fail, logout, password/passkey/TOTP changes, role
  changes, user enable/disable, instance-setting changes. Store actor, target,
  action, timestamp, IP/UA. Viewable by instance admin (household Owner sees
  household-scoped events).
- Session management UI: list active sessions (device/UA, IP, last seen); revoke
  individual sessions or all-but-current.

## Explicitly OUT of scope until a later phase (leave seams, do NOT stub)
- JSON API / OpenAPI / import-export / iCal / attachments → **Phase 5**
- i18n / calendar view / metrics / demo mode → **Phase 6**

## Status
- [x] TOTP enrol/verify
- [x] Passkeys/WebAuthn (login + 2FA, multiple, revoke)
- [n/a] OIDC/SSO — omitted by decision; see below
- [x] Audit log (write on all covered events) + admin view
- [x] Session management UI (list + revoke)
- [x] Login flow updated to insert the 2FA step
- [x] Tests: passkey register/auth/revoke, TOTP enrol/verify + enforced when on,
      audit entries per event, session revoke invalidates immediately — all
      passing (789 tests, green on PostgreSQL and MySQL)
- [x] Readme: Update "Built in phases" section with current phase

## Decisions & deviations made during the build

- **WebAuthn library: `web-auth/webauthn-lib` 5.3.9.** Its full ceremony runs on
  every registration and assertion — origin, challenge, RP-id hash, signature,
  sign-counter regression. Attestation support is "none" only: a self-hosted
  instance has no policy about which vendor made a key, only that the same key
  comes back each time, and anything else would mean shipping metadata-service
  plumbing to validate certificate chains nobody here reasons about.
  `bacon/bacon-qr-code` 3.1.1 renders the TOTP QR as inline SVG (no ext-gd).

- **OIDC/SSO: omitted**, as the phase brief allows. No stub, no table, no route
  and no dead configuration; the seam it would use — the hardened HTTP client
  with the trusted-host allowlist — already exists and is unchanged.

- **TOTP implemented in-house** (`src/Security/Totp.php`) rather than as a
  dependency. RFC 6238 is eighty lines and frozen, and the implementation is
  pinned to the RFC's own published test vectors, which is a stronger guarantee
  than a library version bump.

- **Audit-log retention: 365 days**, `AUDIT_LOG_RETENTION_DAYS`, pruned by
  `maintenance:prune`. A year covers the questions an operator actually gets
  asked; keeping sign-ins for ever is a liability rather than an asset.

- **TOTP secrets are encrypted at rest** with XChaCha20-Poly1305, key derived by
  HKDF from `TOTP_ENCRYPTION_KEY` or, unset, from `SESSION_KEY` — so no new
  required variable. This defends a database dump or a backup, not an attacker
  who owns the running application, and the code says so rather than implying
  more.

- **Recovery codes were added** beyond the phase's literal bullet list. A second
  factor with no recovery path is a way to lose a self-hosted account
  permanently; ten hashed, single-use codes are the standard answer. They belong
  to the *account's second factor* rather than to TOTP — registering a first
  passkey issues them too, since losing your only passkey locks you out exactly
  as hard — which is why the table is `recovery_codes` and the logic sits in
  `RecoveryCodeService` with `TwoFactorService` owning the policy.

- **"Role changes" are audited; "user enable/disable" is not.** The brief lists
  both. Role changes existed (in a controller) and moved into
  `HouseholdSettingsService`, where they are now recorded. Nothing in the
  application can disable an account — the users table has no such column and
  there is no user-administration UI — so no `user.disabled` action was added:
  a filter that can never match would imply a guarantee nothing enforces. It
  arrives with the feature it describes.

- **`sessions.user_id` was never populated before this phase.** `PdoSessionHandler`
  had an `attachUser()` nobody called, so the session list would have been
  permanently empty. The column is now written by the handler itself on every
  session write, driven by the session's own contents — there is no sign-in path
  that can forget to do it. The unused `attachUser()`/`deleteForUser()` methods
  were removed; `SessionRepository` owns listing and revocation.

- **`/logout` moved inside the authenticated route group** so the audit entry has
  an actor to attribute it to.

- **The CSRF token is now minted when the session starts**, not lazily by the
  first template that asks. With the write-close moved into a `finally`, a token
  first created while rendering an error page would be embedded in that page and
  never stored, so the form it decorated would be rejected.

- **A pre-existing MySQL bug in the session handler was fixed.** Its
  UPDATE-then-INSERT inferred "no row" from zero affected rows, but MySQL counts
  rows *changed*: re-writing a session with identical contents inside the same
  second looked like a missing row and attempted an INSERT onto the primary key.
  It now uses the same `reportsMatchedRowsOnUpdate()` check
  `InstanceSettingsRepository` already used. Surfaced by running the new session
  test against MySQL.

- **The session association is written in a `finally`.** The error middleware
  sits outside the session middleware, so a 403 or 404 unwinds past it; writing
  the association after the handler meant PHP's shutdown handler rewrote the row
  with no user id, silently detaching a live session from its account and
  putting it beyond both the security page and a password reset's
  revoke-everything. Found after the suite was green, reproduced against the
  running app, and now covered by a test that drives the real handler (the
  functional tests swap the session out, so nothing else could catch it).

- **`SettingsController` lost its business logic** to `InstanceAdminService` and
  `HouseholdSettingsService`. The audit entries had to be written where the
  change happens, and the change was happening in a controller.

## Definition of done
App runs, all Phase 4 tests green, CI green, no Phase 5+ features stubbed. Then
copy PHASE-5.md over PHASE.md and commit.

**State at the end of the phase:** the app runs (verified in a browser: TOTP
enrolment, the two-step challenge, session list, audit view); 789 tests, PHPCS
and PHPStan level 6 all green; migrations apply and roll back on both PostgreSQL
and MySQL, and the whole suite passes on both; the Docker image builds. Nothing
from Phase 5 or 6 is stubbed.

`PHASE-5.md` does not exist in the repository, so the last step of this
definition is not actionable here — the phase is otherwise complete and ready to
commit.