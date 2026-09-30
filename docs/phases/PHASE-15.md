# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 15 — household membership: provisioning & account self-service

The application has a complete household model — a household is a real scoping
entity, users join it through a membership that carries a role, and isolation is
enforced centrally — but two halves of it were never wired to a screen. There is
no way for an Owner/Admin to **put a second person into their household**, and no
way for a signed-in member to **manage their own account**. This phase builds
both, and only both.

It is the deferred half of the original Phase 6 (household user administration
and account self-service), brought back now with a concrete shape: **a parent is
the Owner/Admin and adds their children as members of the one household.** That
framing is not decoration — it decides the one genuinely open question in this
phase (how a member without their own email gets a password), and it is written
down under "The children-without-email case" below.

## Depends on

Phases 1–14. Specifically the parts this phase reuses rather than rebuilds:

- **P1** — the `households` and `household_memberships` tables, the central
  role + isolation scoping layer, the sessions table, the email-verification and
  password-reset token mechanism (`auth_tokens`, `TokenRepository`), and the
  mailer.
- **P4** — the audit log (`AuditAction`, `AuditLogService`) and session
  revocation (used by revoke-login and by "sign out other sessions").
- **P5** — the permission-scoped file storage and streaming pattern
  (`AttachmentStorage` + the streaming route), reused for avatars.
- **P8–P9** — the design system and the application shell; every new screen
  extends the shell and uses the tokens, and no screen invents its own chrome.

## The one behaviour that is *not* like registration

`AuthService::register()` creates a user **and a brand-new household** and makes
that user its Owner/Admin. That is correct for open sign-up, and it is exactly
what provisioning must **not** do. Adding a member creates a user and a
**membership into the admin's existing household** — no second household is
created. A provisioning path that accidentally reused `register()` would give the
household two families instead of one, so the member-creation code is a distinct
path from the top, sharing only the low-level pieces (create user, issue token,
send mail).

Closed public sign-up is already done and stays out of scope: `RegisterController`
already rejects the route (404) when `registrationAllowed()` is false, so members
join *only* by provisioning once the instance is set up. This phase relies on
that; it does not touch it.

## In scope this phase (build ONLY these)

### A. Household member administration (Owner/Admin only)

- **Member list.** A screen under Settings listing the household's members with
  role, **status** (active / pending / revoked) and last seen. Read for every
  member; the management controls appear only for an Owner/Admin.
- **Add a member.** The Owner/Admin supplies name, email and household role
  (Owner/Admin, Editor, Viewer). This creates the user, creates a membership into
  **this** household with `status = pending`, and sends an invite that lets the
  new member verify their address and set their own password. The admin never
  types the member's password. See the children case below for the email-less
  variant.
- **Reset a member's password.** Triggers the existing reset flow (sends a link).
  The admin never sees or sets the raw password.
- **Revoke login.** Disables the account so it cannot authenticate **and**
  immediately terminates all of that member's sessions (reuse P4 revocation). A
  revoked member is logged out everywhere at once. Re-enabling restores access.
  This is an account-level state (`users.disabled_at`), distinct from removal.
- **Remove from household.** Deletes the membership and handles the member's data
  by isolation mode: **ISOLATED** — their private subscriptions are reassigned to
  an Owner/Admin or deleted, the admin choosing which; **SHARED** — household
  rows stay with the household. Never orphan a row or leave a dangling
  `owner_user_id`.
- **Guards, server-side.** Only an Owner/Admin of *that* household may manage its
  members; an Editor or Viewer hitting any of these endpoints gets **403** from
  the permission layer, not from a hidden button. An Owner/Admin cannot remove or
  demote the **last remaining Owner** — the household must never lock itself out.
- **Audit.** Every one of the above writes an audit entry (actor, target, action,
  timestamp): member created, invite re-sent, password reset triggered, login
  revoked/restored, role changed, member removed.

### B. Account self-service (every member, own account only)

- **Change full name.** Same validation as sign-up (non-empty, ≤ 100 chars).
- **Change email, with re-verification.** The new address is stored as
  `pending_email` and a confirmation link is sent **to the new address**; the
  live `email` is unchanged until the link is followed. The old address stays the
  login until then, and is sent a security notice that a change was requested. A
  new request replaces any pending one; a target already registered to another
  account is rejected at request time.
- **Change password.** Requires the current password (verified, not merely
  present), enforces the sign-up strength rules, and on success offers to sign
  out the member's other sessions while keeping the current one.
- **Avatar.** Upload / change / remove. Stored outside the web root and streamed
  through a permission-scoped route, image types only (detected from the bytes,
  not the filename), size-capped, re-encoded to a sensible display size, with
  initials as the fallback when none is set. Shown wherever a member appears: the
  top bar, the member list, and the assigned-payer field.

These are self-service: they act on the acting user's own account, need no
household permission (like the existing `/profile` routes), and are **not** the
admin flows in section A.

## The children-without-email case (decide before building)

The invite-by-email flow in A assumes each new member has their own email to
receive a link at. A parent adding a young child often will not have that, and
CLAUDE.md forbids an admin setting a plaintext password. These pull against each
other, so the phase names the fork rather than guessing:

- **Default — email invite.** Member gets a link, verifies, sets their own
  password. Honours the non-negotiable exactly. Requires an email per child.
- **Option — admin-set temporary credential for an email-less member.** The
  Owner/Admin creates the account with **no email** (or a placeholder that cannot
  receive mail) and a **one-time temporary password shown once to the admin**,
  which the member must change on first login. The account is flagged
  "must-change-password" until they do, the temporary value is stored only as a
  hash, and the action is audited. This is a *scoped, logged* relaxation of "no
  admin-set password", justified by the deployment (a parent setting up a child's
  device), not a general loophole.

**Proposed default:** build the email-invite path, and add the temporary-password
variant **behind the admin's explicit choice** ("this member has no email") so
the common case stays strict and the child case is possible. Confirm this before
I build, or pick email-only.

## Data-model changes

New migrations, sequenced after the last existing one (`20260601000006`), each
with an explicit `down()`, verified on PostgreSQL and MySQL:

1. **`add_status_to_household_memberships`** — `status` (string, e.g.
   `active` / `pending`), default `active` so existing rows are unaffected.
2. **`add_disabled_at_to_users`** — `disabled_at` (nullable timestamp). Non-null
   means login is revoked. (If the temporary-credential option is chosen, this
   migration also adds `must_change_password` (boolean, default false).)
3. **`add_pending_email_to_users`** — `pending_email` (nullable string).
4. **`add_avatar_path_to_users`** — `avatar_path` (nullable string).

No new table. The email-change confirmation reuses `auth_tokens` via a new
`purpose` constant (`confirm_email_change`) — a string value, not a schema
change. The invite reuses the existing verify-email + password-set mechanism
(add an `invite`/`activate` purpose only if reusing `reset_password` for the
initial set proves awkward — decide at build time, note it in the file).

## Explicitly out of scope (leave clean seams, do NOT stub)

- Closed public sign-up — already enforced; not touched here.
- Multiple households per user / a household switcher — the model supports it
  (`ScopeFactory` already takes a preferred household), but no UI for it is built
  now.
- Instance-admin-level cross-household user management — the instance admin still
  administers the instance, not the contents of households.
- Any new `/api/v1` endpoints for these actions beyond trivial reuse — formal API
  coverage can follow later.

## Decisions & assumptions (confirm or correct before build)

- Member administration lives under **Settings** (household/instance concerns);
  account self-service lives under **Profile** (the account's own page). This
  matches the existing `SettingsController` / `ProfileController` split and the
  Phase 9 nav.
- Avatars are visible to anyone **sharing a household** with the member (needed
  for the member list and assigned-payer), and to the member themselves —
  streamed, not public, and authorised on that basis. Re-encoding uses `ext-gd`;
  PNG/JPEG/WebP/GIF only.
- The children-without-email fork resolves to the **proposed default** above
  unless you say otherwise.
- Removal in ISOLATED mode **prompts** the admin to reassign-or-delete rather than
  choosing silently.

## Status

- [ ] Migrations: membership status, `disabled_at`, `pending_email`,
      `avatar_path` (+ `must_change_password` if chosen)
- [ ] Member list screen (role, status, last seen; controls Owner/Admin-only)
- [ ] Add member → user + membership into **this** household (not a new one) +
      invite/set-password flow
- [ ] Children-without-email path (per the confirmed decision)
- [ ] Admin password reset (triggers existing flow)
- [ ] Revoke login (disable auth + kill sessions) + re-enable
- [ ] Remove member (isolation-aware; no orphans)
- [ ] Guards: Owner/Admin-only (Editor/Viewer 403); cannot drop the last Owner
- [ ] Audit entries for every admin action
- [ ] Self-service: change name
- [ ] Self-service: change email with re-verification (old stays until confirmed)
- [ ] Self-service: change password (current-password check + sign-out-others)
- [ ] Self-service: avatar upload/change/remove + initials fallback + surfaces
- [ ] New strings catalogued in `translations/en.php`
- [ ] Tests below passing on both engines; `composer check` and `i18n:check` clean

## Definition of done

`docker compose up` runs clean, the migrations apply on a fresh DB and roll back,
an Owner/Admin can add a second member into the **same** household and manage
them, every member can manage their own account, the quality gates and
`i18n:check` are green on PostgreSQL and MySQL, and no later idea was stubbed in.
Then update `PHASE.md` to the next phase.

## Tests

- Only an Owner/Admin can manage members (Editor/Viewer → 403 on every endpoint).
- Adding a member creates a membership in the **admin's** household and creates
  **no** new household; the member reaches the household's data per isolation
  mode and role.
- A provisioned member gets a verify/set-password flow; no admin-set plaintext
  password is stored. (If the temporary-credential option is built: it is stored
  only as a hash, forces a change on first login, and is audited.)
- Revoke login disables authentication **and** invalidates active sessions
  immediately; re-enable restores access.
- The last Owner cannot be removed or demoted.
- Removal honours isolation (ISOLATED: private rows reassigned or deleted per the
  admin's choice, no orphaned `owner_user_id`; SHARED: household rows remain).
- Self-service: name change persists; email change stores `pending_email`, keeps
  the old address live, mails the new one, and swaps only on confirmation; a
  target email already in use is rejected.
- Password change fails on a wrong current password (hash unchanged) and succeeds
  otherwise; "sign out other sessions" revokes others and keeps the current one.
- Avatar upload rejects a disguised or oversized file, stores outside the web
  root, streams back, is **not** fetchable by a user outside the household, and
  remove restores the initials fallback.
- Audit-log entries are written for each admin action with the correct
  actor/target.