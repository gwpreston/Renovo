# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 26 — members & roles

The household's people, their roles and what each role may do, rebuilt to the
prototype. The **behaviour** — adding a member, invites, resets, revoking login,
removal, the last-Owner guard, auditing — is Phase 15's. This phase is its screen,
plus two things the prototype adds: a permission matrix generated from the rules
the server enforces, and a read-only statement of the instance's isolation mode.

**Phase 15 must be built first.** Its status list in the repository is still
unticked; if it has not been built, build it before this phase (its behaviour and
tests are the foundation here), and add the "Members & roles" rail item Phase 19
left out.

## Depends on

- **Phase 15** — membership status, invites, `disabled_at`, removal with
  reassign-or-delete, the last-Owner guard, avatars, audit entries.
- **The Contributor role** — already built.
- **Phase 20** — private rows (their handling on removal, their exclusion from
  shares).
- **`PermissionService`** — the single source for what a role may do.

## In scope this phase (build ONLY these)

### A. The page

Intro: "Everyone in the {household} household and what their role lets them do.
Only Owners can change roles, invite people or remove them." **Invite member**
as a secondary header button, Owner/Admin only.

### B. Members table

| Column | Content |
| --- | --- |
| Member | avatar (initials fallback), name, email; "· you" on your own row |
| Role | a select for an Owner/Admin on others' rows (Owner/Admin, Editor, Contributor, Viewer); plain text otherwise and on your own row |
| Status | Active (ok) / Invite pending (warn) / Login revoked (bad) — each with text |
| Last seen | ICU relative date from the latest session, "never" for pending |
| Monthly share | the member's monthly share after splits and subscription count — as the viewer is entitled to see it: a non-payer never sees private rows counted, and in ISOLATED a member sees only their own share (others show "—") |
| Actions | Resend invite (pending) · Send reset · Revoke login / Restore login · Remove — Owner/Admin only, not on your own row |

A role change submits on change (htmx; a Save button without script), passes the
last-Owner guard and writes an audit entry. **Remove** opens a confirmation that,
where the member owns private rows or the mode is ISOLATED, asks to reassign them
to an Owner/Admin or delete them — Phase 15's prompt, now also for private rows
(Phase 20).

Below 768px the table becomes cards with the same controls.

### C. Invite modal

"Invite to {household}", "They'll get an email link that expires in {N} days"
(N from the existing token lifetime, not a fixed 7). Name, Email, Role as cards
with a one-line description each — **Editor, Contributor, Viewer**; a new member
is never invited as Owner (promote afterwards). **This member has no email**
toggle for Phase 15's temporary-credential path, if that path was built.

### D. What each role can do

The prototype's matrix — rows by capability, columns by role, cells Yes / Own
only / No, each with text and an icon — **generated from `PermissionService`**
and the scoping rules, never hand-written, so the page cannot claim a permission
the server does not enforce. Rows:

- View subscriptions & totals
- Add subscriptions
- Edit prices, splits & invoices
- Manage budgets
- Categories, tags & payment methods
- Import & bulk edit
- Members, backups & settings

Caption: "'Own only' means only rows that member pays for."

### E. Data visibility

A read-only card: the instance's mode (**Shared** or **Isolated**) with a
one-paragraph explanation of what it means for this household, and — for an
instance administrator only — a link to the instance setting where it is changed
(decision 3). The "Only me" note, corrected to match Phase 20: "A subscription
set to 'Only me' is hidden from everyone else in either mode, and its cost is
left out of their totals."

## Data-model changes

**None** beyond Phase 15's.

## Explicitly out of scope

- Changing isolation from this page (decision 3).
- A household switcher (decision 16).
- Instance-wide user administration.

## Decisions & assumptions (confirm or correct before build)

- Phase 15 is built first if it is not already.
- The matrix is generated from `PermissionService`, and its rows are the ones
  above.
- New members cannot be invited as Owner.
- Monthly share is shown only as far as the viewer may see it.

## Status

- [ ] Phase 15 confirmed built (or built first)
- [ ] Members table + mobile cards; inline role change with guard and audit
- [ ] Actions: resend, reset, revoke/restore, remove (private-row prompt)
- [ ] Invite modal (role cards; no-email path if built)
- [ ] Generated permission matrix
- [ ] Read-only data visibility card
- [ ] New strings in `translations/en.php`
- [ ] `composer check`, `i18n:check` green on both engines

## Definition of done

An Owner/Admin can manage every member from this page; everyone else sees it
read-only; the matrix matches what the server enforces; shares reveal nothing the
viewer may not see; all palettes × themes, wide and narrow; gates green on both
engines. Then update `PHASE.md` to the next phase.

## Tests

- Matrix cells equal `PermissionService` answers (and scoping's "own only") for
  every role and row — a change to either fails the test.
- Editor, Contributor and Viewer: no management controls rendered, and 403 on
  every management endpoint.
- The last Owner cannot be demoted through the inline select.
- Removing a member who owns private rows requires a reassign-or-delete choice
  in SHARED as well as ISOLATED, and leaves no orphaned `owner_user_id`.
- Monthly share excludes private rows for non-payers; ISOLATED shows "—" for
  others.
- Invite cannot create an Owner; the expiry text matches the token lifetime.
- Every action writes its audit entry.
- `AccessibilityTest` passes on the page and the modal.