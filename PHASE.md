# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 27 — profile

One page for everything a person sets for their own account: who they are, their
password, two-step verification and passkeys, where they are signed in, and how
the application looks and behaves for them. Today these are spread across
`/profile`, the appearance section of Settings and the security pages; the
prototype gathers them, and this phase moves them onto `/profile` with the old
routes redirecting. It is self-service throughout — it acts on the signed-in
account only and needs no household permission, so a Viewer can use all of it.

## Depends on

- **Phase 15** — name, email change with re-verification, password change with
  "sign out other sessions", avatars.
- **Phase 4** — TOTP, passkeys, recovery codes, the session list and revocation.
- **Phase 18** — the palette preference; **Phase 6** — density, week start,
  language, landing view; theme (Phase 1).

## In scope this phase (build ONLY these)

### A. Who you are

Avatar with **Upload picture** / **Remove** — PNG, JPEG, WebP or GIF, re-encoded,
with the size cap Phase 15 set (the prototype's "JPG or PNG, up to 512 KB" is
replaced by the real rule). Name. Email, with the hint "Changing it sends a
confirmation link to the new address" and, while a change is pending, "Waiting
for confirmation of {new address} — resend / cancel". **Save changes**.

### B. Password

Current password, new password, confirm, a **Sign out my other sessions**
checkbox (ticked by default), **Update password**.

### C. Two-step verification & passkeys

- **Authenticator app**: "On since {date} · {N} of 10 recovery codes left", with
  Turn off (re-authenticated) — or Set up when off.
- **Passkeys**: each with its name and date added, rename and remove; **Add a
  passkey**.
- **New recovery codes** (shown once, replacing the old set).

### D. Where you're signed in

Each session: device from the user agent, **IP address** and last seen — no
location (decision 17). "This device" badge on the current one; **Sign out** on
each other session; **Sign out everywhere else**.

### E. Appearance & preferences

- Theme — System / Light / Dark
- Colour scheme — the five palette swatches (moved here from Phase 18's
  interim place)
- List density — Comfortable / Compact
- Week starts on — Monday / Sunday
- Language — only the catalogues that exist (today: English); the control is
  hidden while there is one
- Open on — Dashboard / Subscriptions / Calendar

Each saves on change (htmx; one Save button without script).

### F. Sign out

At the foot of the page, as well as in the rail's user card.

## Data-model changes

**None.**

## Explicitly out of scope

- Session locations (decision 17).
- OIDC-linked identities (OIDC is not built).
- New preferences.

## Decisions & assumptions (confirm or correct before build)

- Old appearance/security routes **redirect** to the matching `/profile`
  section rather than being removed outright, so bookmarks keep working.
- "Sign out my other sessions" is ticked by default on password change.
- The language control is hidden while only English exists.

## Status

- [ ] Who you are (avatar rules, pending email state)
- [ ] Password with sign-out-others
- [ ] TOTP, passkeys, recovery codes
- [ ] Sessions (no location), sign out one / everywhere else
- [ ] Appearance & preferences, including the palette picker
- [ ] Redirects from the old routes; rail user card active on `/profile`
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

## Definition of done

Every account setting is on `/profile`, works for every role, with and without
script, and the old routes land on the right section; all palettes × themes,
wide and narrow; gates green on both engines. Then update `PHASE.md` to the next
phase.

## Tests

- A Viewer can change every setting on the page; nobody can change another
  account's.
- Password: a wrong current password leaves the hash unchanged; the checkbox
  revokes other sessions and keeps the current one.
- Email change keeps the old address live until confirmation.
- Avatar: disguised and oversized files rejected; remove restores initials.
- Sessions: revoking one invalidates it immediately; no location is rendered.
- Every old route redirects to its `/profile` section.
- `AccessibilityTest` passes.