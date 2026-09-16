# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

## Current phase
**Phase 5 — API, interoperability & files.**

Goal: make the app interoperable and automatable without breaking clients.

## Depends on
Phases 1–4 — a stable data model and service layer to expose, roles/isolation to
enforce, the file-safe HTTP client.

## In scope this phase (build ONLY these)
- Versioned API under `/api/v1`, reusing the existing service layer. Full CRUD
  for subscriptions **including edit/update** (no delete-and-recreate). Every
  field editable in the UI is editable via the API. All endpoints respect roles
  + isolation.
- API auth via tokens (issue/revoke in the UI).
- OpenAPI 3 spec as the source of truth: served/downloadable, kept in sync;
  validate it against the routes in CI.
- CSV/JSON import with a proper importer UI: upload → map columns → preview →
  commit. Preset column templates for common competitor exports.
- Full backup/restore from the UI: export everything (subscriptions, categories,
  tags, budgets, settings, logos) and re-import without touching the DB. Restore
  respects roles/isolation.
- iCal feed export: authenticated, read-only `.ics` of renewals, trial
  conversions, and cancel-by dates.
- Invoices & receipts: attach files (PDF/image) to a subscription and optionally
  a payment/period. Store outside the web root (or object storage); stream via an
  authorised, permission-scoped route. Validate type/size; reject dangerous
  uploads.

## Explicitly OUT of scope until a later phase (leave seams, do NOT stub)
- i18n / calendar VIEW / UX polish / metrics / demo mode → **Phase 6**
- Bank/transaction sync (Plaid/GoCardless/Firefly) → **not in v1 at all**

## Status
- [x] `/api/v1` group, full CRUD incl. edit, roles + isolation enforced
- [x] API tokens (issue/revoke)
- [x] OpenAPI 3 spec served + validated in CI
- [x] CSV/JSON import (upload → map → preview → commit) + templates
- [x] Backup/restore (respects isolation)
- [x] iCal feed (auth-gated, read-only)
- [x] Invoices/receipts upload + permission-scoped streaming + validation
- [x] Tests: API contract vs OpenAPI (incl. edit), API permissions (Viewer 403)
      + isolation, import round-trip, backup→restore fidelity, iCal contents +
      auth, attachment scope + bad-type rejection — all passing

## Decisions & deviations made during the build

**Token format.** `rnv_<24 hex public id>_<64 hex secret>`. The public half is
stored in the clear and indexed; only a SHA-256 of the secret is kept. Splitting
them keeps verification to one indexed row read instead of a scan hashing every
candidate. SHA-256 rather than a password KDF is correct *because* the secret is
256 bits of `random_bytes` — there is no dictionary to stretch against, so a slow
hash would cost every API call and buy nothing.

**OpenAPI approach.** Hand-authored `openapi/openapi.yaml`, served verbatim and
converted to JSON on request. Not generated from annotations: a generated
document describes whatever the code does, including the parts that are wrong,
which makes "the API matches its spec" a tautology. A test asserts a *bijection*
between the document's operations and Slim's route table, and runs in the
lint/static CI job so it fails in seconds. Needed `symfony/yaml` (PHP has no
bundled YAML parser and `ext-yaml` was not in the CI extension list).

**Attachment storage.** `var/attachments/<household id>/<32 hex>.<detected ext>`
— outside the web root, unlike logos, because an invoice carries an address and a
card number. Type comes from `finfo` magic bytes; the extension and declared MIME
are ignored. `ext-zip` and `ext-fileinfo` added to composer.json (both were
already present in the Docker image and CI).

### Other decisions worth recording

- **PUT replaces; no PATCH.** `SubscriptionService::validate()` is form-shaped and
  absence-sensitive — an absent `category_id` writes null, `is_active` is tested
  `!== '0'` so JSON `false` would read as true, `is_trial` is tested `!== '1'` so
  JSON `true` would read as false. Rather than loosen rules the web form depends
  on, `Application\Api\SubscriptionPayload` translates JSON into the dialect the
  service already speaks, and always produces a *complete* payload. Tests cover
  both boolean traps and the three-state `reminder_days` field.
- **The API never reads the session.** This is what makes the CSRF exemption on
  `/api/v1` safe; the exemption additionally requires a bearer header, so a route
  mounted there without the API middleware does not silently lose protection.
  Covered by a test.
- **The calendar feed takes its token from the query string** because calendar
  clients cannot send headers. Enforced structurally — a separate middleware
  class applied to that one route, accepting read-only tokens only — rather than
  by a condition inside the shared middleware that a later route could be added
  on the wrong side of.
- **Backups exclude instance settings**, user accounts, API tokens and the audit
  log. Those belong to the instance, not the household, and a household Owner who
  could round-trip them could reconfigure the server from the backup screen. This
  was flagged as an assumption in the plan and approved.
- **Restore is additive**, never destructive. Members are matched by email;
  someone no longer in the household hands their rows to the restorer. The
  household's *name* is exported but not read back — a restore lands in a
  household that already exists and already has a name its members chose, and
  renaming it as a side effect of importing subscriptions would be a surprise.
  The tag vocabulary, including labels not currently applied to anything, is
  restored.
- **Splits, scheduled price changes and usage have no v1 endpoints.** They are
  editable in the UI, so this is a deviation from "every field editable in the
  UI is editable via the API" and is recorded as one. Each is a sub-resource
  with rules of its own (a split must total its shares; a scheduled price has an
  effective date that interacts with the price history; a usage count has a
  period it is measured over), and a half-considered endpoint is hard to change
  once clients depend on it. They are readable as part of a subscription
  (`split_mode`, `usage_count`, `usage_rating`) and the deferral is stated in
  the OpenAPI document rather than left as an apparent oversight.
- **Attaching a document requires *write* access to the subscription**, not just
  read access. The two differ only for a shared-cost split participant — and
  that is the case that matters: in ISOLATED mode the scoping layer forces a new
  row's owner to its creator, so a read-only rule would have let a participant
  file an invoice against somebody else's bill that its owner could not then
  see. Found in review; covered by a test that fails without the check.
- **The API list performs the same lazy catch-up as the web list**, gated on the
  token being write-capable as well as on the role. Without it a polling client
  would read payment dates in the past until somebody opened a browser.
- **Import gained explicit minor-unit price fields.** Found by the round-trip
  test: this application's own JSON export writes `price_minor`, and mapping that
  onto the decimal `price` field made every imported amount a hundred times too
  large. A column of "1099" is either £10.99 or £1,099.00 and nothing about the
  number says which, so the unit is named rather than guessed.
- **`MembershipRepository::findMembersOfHousehold()` now also returns `email`.**
  Backups identify members by address, because ids mean nothing in the instance an
  archive is restored into.
- **The scheduler container now mounts the shared `var/` volume.** It runs
  `maintenance:prune`, which clears abandoned import uploads — files written by
  the *app* container. Without the mount it would have been tidying its own empty
  directory for ever.
- **Two PHP-version fixes made along the way:** `finfo_close()` is deprecated in
  8.5 (switched to the `finfo` object API), and `fgetcsv()`'s `$escape` parameter
  must now be passed explicitly (passed as `''`, which is both the future default
  and what RFC 4180 actually specifies).

## Definition of done
App runs, all Phase 5 tests green, CI green (incl. spec validation), no Phase 6
features stubbed. Then copy PHASE-6.md over PHASE.md and commit.