# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
CLAUDE.md = standing rules · ROADMAP.md = candidates after v1. When this phase
is done, archive this file as `docs/phases/PHASE-30.md` and replace it.

# Phase 30 — API catch-up: tags and payment-method logos

The first phase after v1. It closes two gaps where `/api/v1` falls short of the
web interface **and its stated reason for doing so no longer holds**:

- **Tags can be listed and deleted but not created or renamed.** The API says
  tags are made only by naming them on a subscription. That was true until
  Phase 28 gave Settings a tag section with its own create and rename routes
  (`POST /tags`, `POST /tags/{id}`). A client can now do less with a tag than a
  member in a browser can.
- **Payment-method logos can't be uploaded or cleared.** The API leaves them out
  as "the same line the subscription resource draws around its own logo", but
  subscriptions *do* have `POST`/`DELETE /subscriptions/{id}/logo`. The reason
  contradicts the code next to it.

Everything here is additive: no existing request or response changes shape, so
no client breaks. It is a v1.1 candidate.

ROADMAP.md ranks encrypting notification channel secrets first. This phase goes
ahead of it at the owner's request, and that item keeps its ranking for the
phase after this one.

## Depends on

- **Phase 5** — the versioned API, tokens, the OpenAPI bijection test and the
  prose reference's coverage test.
- **Phase 17** — payment methods and their logos (`LogoStorage`).
- **Phase 28** — `TagService::create` and `TagService::rename`, which the web's
  tag section already calls.

## In scope this phase (build ONLY these)

| Method | Path | Permission | Returns |
| --- | --- | --- | --- |
| `POST` | `/api/v1/tags` | `tag.manage` | `201` + the tag |
| `PUT` | `/api/v1/tags/{id}` | `tag.manage` | `200` + the tag |
| `POST` | `/api/v1/payment-methods/{id}/logo` | `category.manage` | `200` + the method |
| `DELETE` | `/api/v1/payment-methods/{id}/logo` | `category.manage` | `204` |

- **Tags** take `{ "name": "…" }` and go through the same `TagService` methods
  as the web form. Its rules apply unchanged: required, at most 50 characters,
  and unique in the household ignoring case. "tv" and "TV" are one tag, as they
  are when typed on a subscription. A rename renames the tag on every
  subscription that carries it.
- **Logos** are `multipart/form-data` with a `logo` part, like the subscription
  logo: PNG, JPEG, GIF or WebP, with the type read from the file's contents.
  Uploading replaces any earlier logo and deletes its file. Clearing removes the
  logo and its file.
- The OpenAPI document and `docs/api.md` describe all four, and the out-of-date
  "no create endpoint" and "logos are uploaded on the web screen" text is
  removed from the spec, the prose reference and the controller.

## Data-model changes

**None.**

## Explicitly out of scope (leave clean seams, do NOT stub)

- Shared-cost splits, scheduled price changes and the usage counter. These are
  still deferred for the reasons recorded in Phase 5.
- Budgets, price history, forecasts and statistics as API resources.
- Setting a payment method's `icon` through the API. Today it is set only when
  defaults are seeded.
- Bulk edit, saved views, members, notifications, import, backup and instance
  administration.

Each of these is listed in ROADMAP.md under **API**.

## Decisions & assumptions (confirm or correct before build)

- **No new permission.** Tags use `tag.manage` and payment-method logos use
  `category.manage`, the same permissions their web routes use. The role matrix
  in `docs/api.md` doesn't change.
- **The logo replace is a service method** (`PaymentMethodService::replaceLogo`)
  rather than storage calls in the controller. This keeps the rule "logic in
  services", and leaves the web's `update()` path as it is.
- **An existing tag name is a `422`, not a `200` with the existing tag.** An
  idempotent "create or return" would differ from the web form, which refuses
  a duplicate. A client that wants that behaviour can `GET /tags` first.

## Status

- [x] Routes for the four endpoints, each naming its permission
- [x] `TaxonomyApiController`: tag create and rename, payment-method logo upload
      and clear; the stale docblock rewritten
- [x] `PaymentMethodService::replaceLogo`
- [x] `openapi/openapi.yaml` and `docs/api.md` describe all four; stale text gone
- [x] Tests: contract, Viewer and Contributor 403, cross-household 404,
      duplicate 422, logo type rejection (`ApiTaxonomyTest`, `ApiPermissionTest`)
- [x] `CHANGELOG.md` Unreleased entry; ROADMAP.md updated
- [ ] `composer check` and the API coverage tests green on both engines —
      Postgres green; MySQL leg still to run

## Decisions as built

- **The OpenAPI document is version 1.1.0.** The change is additive, so it is a
  minor version; the path prefix stays `/api/v1`.
- **A `logo` part with no file in it is a `422`**, not a `200` that changes
  nothing. A missing part is still a `400`, as for a subscription's logo.
- **`ApiTestCase::containerOverrides()`** lets an API test replace a container
  entry. The logo tests use it to write into a temporary directory instead of
  `public/assets/logos`.

## Definition of done

An API client can do everything with tags and payment-method logos that the
Settings page can. Every new route names its permission, and roles and isolation
are enforced through the same scoping layer as the web interface. The spec, the
prose reference and the route table agree, and the gates pass on Postgres and
MySQL. Then archive this file and start the next phase.

## Tests

- Creating a tag returns `201` and the tag. A name that differs from an
  existing one only in case returns `422`.
- Renaming a tag returns `200`, and a subscription carrying it shows the new
  name.
- A Viewer gets `403` on all four routes. A Contributor gets `403` on all four,
  since neither permission is a Contributor's.
- Another household's tag or payment-method id returns `404`.
- Uploading a PNG returns `200` with `logo_path` set. A non-image upload returns
  `422`, and a request with no `logo` part returns `400`.
- Clearing returns `204`, after which `logo_path` is null and the file is gone.
- `OpenApiCoverageTest` and `ApiDocCoverageTest` pass.
