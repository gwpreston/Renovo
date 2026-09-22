# The Renovo API, version 1

A per-endpoint reference for `/api/v1`.

**`openapi/openapi.yaml` is the source of truth**, not this file. It is written
by hand, served verbatim at `/api/v1/openapi.yaml` (and converted at
`/api/v1/openapi.json`), and checked against the application's own route table
in CI in both directions — an endpoint that exists without being described there
fails the build, and so does a description with no endpoint behind it. This
document is the narrative companion: the same contract with the reasoning,
the permission each route demands, and worked examples. Where the two ever
disagree, the YAML is right and this file is stale.

Generate a client from the YAML. Read this to understand what you are
generating.

- [Base URL and versioning](#base-url-and-versioning)
- [Authentication](#authentication)
- [Permissions](#permissions)
- [Conventions](#conventions)
- [Errors](#errors)
- [Endpoint reference](#endpoint-reference)
  - [Meta](#meta)
  - [Subscriptions](#subscriptions)
  - [Logos](#logos)
  - [Attachments](#attachments)
  - [Categories and tags](#categories-and-tags)
  - [Calendar feed](#calendar-feed)
- [Schemas](#schemas)
- [Not in version 1](#not-in-version-1)

---

## Base URL and versioning

Every path is prefixed `/api/v1`, defined once in `App\Application\Api\ApiPath`
and shared by the three pieces of plumbing that must agree on it: the route
file that mounts the API, the CSRF middleware that skips it, and the error
handler that renders JSON for it.

```
https://your-instance.example/api/v1
```

The OpenAPI document declares its server as `/`, so the base URL is whatever
host the instance is served from. Renovo is self-hosted; there is no central
endpoint.

---

## Authentication

Issue a token under **Settings → API tokens**. It looks like:

```
rnv_<public id>_<secret>
```

Only a hash of the secret is stored. The token is displayed once, at creation,
and cannot be recovered afterwards — reissue rather than recover. The `rnv_`
prefix exists so that a leaked token is recognisable on sight in a log or a
paste.

Send it as a bearer token:

```bash
curl -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/subscriptions
```

### Session cookies are not accepted

This is a security property, not an oversight. The API middleware never reads
the session, so no route behind it can be driven by a browser's ambient
cookie — and because no ambient credential can authenticate an API request,
there is nothing for a cross-site request to forge. That is precisely what
makes it safe for these paths to be exempt from CSRF tokens. A valid browser
session with no token gets `401`, and there is a test that says so.

### A token narrows; it never grants

A token carries one of two abilities, `read` or `write` — two values rather
than a permission list, deliberately. The request still runs under the
household role of the account that issued the token, and the scope is built by
the same `ScopeFactory` the browser uses. So:

- A **Viewer's write-capable token still cannot write.** It fails the same
  permission check the browser would, with `403`.
- A **read-only token is refused any unsafe method outright**, before any route
  runs, with `403`. Safe methods are `GET`, `HEAD` and `OPTIONS`; everything
  else is unsafe.

What the ability adds is the power to issue a credential *weaker* than the
account behind it — which is what makes one safe to paste into a calendar
client or a cron script.

### Isolation applies identically

An API request is scoped exactly as the same user's browser session would be.
There is no second set of visibility rules to keep in step: the instance's
isolation mode (`shared` or `isolated`) and the caller's household role are
applied centrally, to every read and every write, through the same code path
the web interface uses.

---

## Permissions

Each route names the permission it requires. These are the wire strings —
the same values `GET /me` returns in its `permissions` array.

| Method | Path | Permission |
| --- | --- | --- |
| `GET` | `/openapi.yaml` | *public* |
| `GET` | `/openapi.json` | *public* |
| `GET` | `/me` | *any valid token* |
| `GET` | `/subscriptions` | `subscription.view` |
| `POST` | `/subscriptions` | `subscription.create` |
| `GET` | `/subscriptions/{id}` | `subscription.view` |
| `PUT` | `/subscriptions/{id}` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}` | `subscription.delete` |
| `POST` | `/subscriptions/{id}/logo` | `subscription.update` |
| `DELETE` | `/subscriptions/{id}/logo` | `subscription.update` |
| `GET` | `/subscriptions/{id}/attachments` | `subscription.view` |
| `POST` | `/subscriptions/{id}/attachments` | `attachment.manage` |
| `GET` | `/subscriptions/{id}/attachments/{attachmentId}` | `subscription.view` |
| `DELETE` | `/subscriptions/{id}/attachments/{attachmentId}` | `attachment.manage` |
| `GET` | `/categories` | `subscription.view` |
| `POST` | `/categories` | `category.manage` |
| `PUT` | `/categories/{id}` | `category.manage` |
| `DELETE` | `/categories/{id}` | `category.manage` |
| `GET` | `/tags` | `subscription.view` |
| `DELETE` | `/tags/{id}` | `tag.manage` |
| `GET` | `/calendar.ics` | `subscription.view` |

Start with `GET /me`: it reports the role, the isolation mode and the resolved
permission list for the token you are holding, so a client can ask what it is
allowed to do rather than discover it from a `403`. It is computed by the same
`PermissionService` the routes assert with, so what it says and what the API
allows cannot disagree.

### What each role holds

`GET /me` returns **every** permission the caller holds, not only the ones this
API exposes — several below are reachable solely through the web interface.
The list arrives in the order shown.

| Permission | Viewer | Contributor | Editor | Owner/Admin |
| --- | :-: | :-: | :-: | :-: |
| `subscription.view` | ✓ | ✓ | ✓ | ✓ |
| `subscription.create` | | ✓ | ✓ | ✓ |
| `subscription.update` | | ✓ | ✓ | ✓ |
| `subscription.delete` | | ✓ | ✓ | ✓ |
| `category.manage` | | | ✓ | ✓ |
| `tag.manage` | | | ✓ | ✓ |
| `budget.manage` | | ✓ | ✓ | ✓ |
| `price.manage` | | ✓ | ✓ | ✓ |
| `split.manage` | | ✓ | ✓ | ✓ |
| `usage.record` | | ✓ | ✓ | ✓ |
| `subscription.bulk_edit` | | | ✓ | ✓ |
| `household.view` | | ✓ | ✓ | ✓ |
| `household.manage` | | | | ✓ |
| `attachment.manage` | | ✓ | ✓ | ✓ |
| `data.import` | | | ✓ | ✓ |
| `backup.manage` | | | | ✓ |
| `audit.view` | | | | ✓ |
| `instance.manage` | | | | |

A **Contributor** holds what a writer holds, minus the four that cannot be
confined to one member's own rows: `category.manage` and `tag.manage` change
names that every subscription in the household shares, and `data.import` and
`subscription.bulk_edit` write across rows by definition. Everything a
Contributor *can* write, the scoping layer fences to rows they own — the
permission says they may edit a subscription, and the repository decides whose.

So a **Viewer holds exactly one permission**, `subscription.view`. That is what
makes "a Viewer's write-capable token still cannot write" concrete: the token's
ability permits the method, and then every mutating route fails its permission
check anyway.

Two rows do not follow the household role:

- **`audit.view`** is granted to an instance administrator *or* a household
  Owner/Admin, and the repository decides which rows each one sees —
  instance-wide for the administrator, household-scoped for the Owner.
- **`instance.manage`** comes from the `is_instance_admin` flag alone. No
  household role grants it, and it grants no household data access in return.

Restoring a backup asks for `backup.manage` rather than an ordinary write
permission, because it replaces what the household holds rather than editing a
row in it.

---

## Conventions

**Money is an integer in minor units**, always beside its currency.
`price_minor: 1999` with `currency: "GBP"` is £19.99. Never a decimal: JSON
numbers are binary floats in most clients, 19.99 is not representable as one,
and that is most of the reason this application stores integers everywhere.

**There is no `PATCH`. `PUT` replaces.** To change one field, `GET` the
resource, edit the representation and `PUT` it back. A partial body would clear
the fields it omitted — the service's input is absence-sensitive, so a partial
write is the one shape that could half-write a row. The logo is the single
exception: it is a file with endpoints of its own, and a `PUT` leaves it alone.

**`reminder_days` has three distinct states**, and conflating them is the
easiest mistake to make:

| Value | Meaning |
| --- | --- |
| omitted, or `null` | Use the account's notification preference. |
| `[]` | Never remind me about this one. |
| `[30, 7, 1]` | Lead times in days, for this subscription alone. |

**Envelopes.** A successful response wraps its payload in `data`. A list adds
`meta` with the page. An error replaces both with `error`.

**Dates** are `YYYY-MM-DD`. **Timestamps** are ISO 8601 date-times.

**Read-only fields** are returned but ignored on write: `id`, `anchor_day`,
`category_name`, `split_mode`, `usage_count`, `usage_rating`, `logo_path`,
`monthly_minor`, `yearly_minor`, `next_charge_date`, `cancellation_deadline`,
`days_until_next_payment`, `created_at`, `updated_at`.

---

## Errors

Every error is JSON, in one shape:

```json
{
  "error": {
    "status": 422,
    "title": "Unprocessable Entity",
    "message": "The subscription could not be saved.",
    "errors": {
      "next_payment_date": "A recurring subscription needs a next payment date."
    }
  }
}
```

`errors` maps a field name to a message and appears on a `422`.

| Status | When |
| --- | --- |
| `400` | The request was malformed — a bad multipart body, an unreadable upload. |
| `401` | No token presented, or it is revoked, expired, malformed or unknown. The four are one answer. |
| `403` | The token's role does not permit this, **or** a read-only token attempted an unsafe method, **or** a write-capable token was offered to the calendar feed. |
| `404` | No such row — **or** one the caller may not see. |
| `422` | The body was understood but is not valid. |

Two of these are deliberately uninformative:

- **`401` collapses four causes into one.** Distinguishing "expired" from
  "never existed" tells an attacker which of their guesses was once real.
- **`404` means "no such row, or not yours", indistinguishably.** Probing for
  another household's ids therefore tells the prober nothing. A client should
  not read `404` as proof a record has been deleted.

Error messages are localised to the token holder's language, resolved from the
account behind the token rather than the instance default.

---

## Endpoint reference

### Meta

#### `GET /api/v1/openapi.yaml` · `GET /api/v1/openapi.json`

The API description, as YAML or as JSON. Public — no token. A client needs this
in order to learn how to authenticate, and it contains no instance data.

#### `GET /api/v1/me`

The token's identity, role and effective permissions. Requires only a valid
token; no permission gate.

```bash
curl -H "Authorization: Bearer rnv_..." \
     https://your-instance.example/api/v1/me
```

```json
{
  "data": {
    "user": {
      "id": 1,
      "email": "sam@example.com",
      "display_name": "Sam",
      "is_instance_admin": false
    },
    "household_id": 1,
    "role": "editor",
    "isolation_mode": "shared",
    "permissions": [
      "subscription.view",
      "subscription.create",
      "subscription.update",
      "subscription.delete",
      "category.manage",
      "tag.manage",
      "budget.manage",
      "price.manage",
      "split.manage",
      "usage.record",
      "subscription.bulk_edit",
      "household.view",
      "attachment.manage",
      "data.import"
    ]
  }
}
```

`role` is one of `owner_admin`, `editor`, `contributor`, `viewer`, or `null` when
the account
holds no household membership. `isolation_mode` is `shared` or `isolated`.

Responses: `200`, `401`.

---

### Subscriptions

#### `GET /api/v1/subscriptions`

List subscriptions. Returns only what the token's role and the instance's
isolation mode allow — the same rows the same user would see in the web
interface.

| Parameter | Type | Notes |
| --- | --- | --- |
| `q` | string | Search names and notes. |
| `category` | integer | Category id. |
| `owner` | integer | Owner user id. |
| `currency` | string | ISO 4217 code. |
| `type` | enum | `recurring`, `one_off`, `lifetime`. |
| `inactive` | `"1"` | Include inactive subscriptions. |
| `sort` | enum | `name`, `next_payment`, `price`, `created`. |
| `dir` | enum | `asc`, `desc`. |
| `page` | integer | Minimum 1. |
| `per_page` | integer | 1–100, default 25. |

Two things hold whatever `sort` and `dir` say, so that a page of results means
the same on either supported database engine: inactive subscriptions come after
active ones, and a row whose sort column is empty — a lifetime licence has no
next payment date — comes after the rows that have a value there.

```bash
curl -H "Authorization: Bearer rnv_..." \
     "https://your-instance.example/api/v1/subscriptions?sort=next_payment&per_page=50"
```

```json
{
  "data": [ { "id": 12, "name": "Netflix", "price_minor": 1099, "currency": "GBP" } ],
  "meta": { "page": 1, "per_page": 50, "total": 37, "total_pages": 1 }
}
```

Responses: `200`, `401`, `403`.

#### `POST /api/v1/subscriptions`

Create a subscription. Body is a [SubscriptionInput](#subscriptioninput).
Returns `201` with a `Location` header pointing at the new resource.

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -H "Content-Type: application/json" \
     -d '{
           "name": "Netflix",
           "price_minor": 1099,
           "currency": "GBP",
           "billing_cycle": "monthly",
           "next_payment_date": "2026-10-04",
           "tags": ["streaming"]
         }' \
     https://your-instance.example/api/v1/subscriptions
```

Responses: `201`, `401`, `403`, `422`.

#### `GET /api/v1/subscriptions/{id}`

Fetch one subscription. Responses: `200`, `401`, `403`, `404`.

#### `PUT /api/v1/subscriptions/{id}`

Replace a subscription. A **full** replacement: any field the body omits takes
its documented default, so read the resource, edit the representation and send
it back rather than sending only what changed. The logo is untouched.

Responses: `200`, `401`, `403`, `404`, `422`.

#### `DELETE /api/v1/subscriptions/{id}`

Delete a subscription. Responses: `204`, `401`, `403`, `404`.

---

### Logos

#### `POST /api/v1/subscriptions/{id}/logo`

Replace the subscription's logo. `multipart/form-data` with a `logo` part —
PNG, JPEG, GIF or WebP. The type is read from the file's contents, not from its
name or its declared content type.

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -F "logo=@netflix.png" \
     https://your-instance.example/api/v1/subscriptions/12/logo
```

Returns the updated subscription. Responses: `200`, `400`, `401`, `403`, `404`,
`422`.

When no logo has been uploaded, the icon is fetched from the domain in
`website_url` — through the instance's guarded HTTP client, and cached per
domain.

#### `DELETE /api/v1/subscriptions/{id}/logo`

Remove the logo. Responses: `204`, `401`, `403`, `404`.

---

### Attachments

Invoices and receipts filed against a subscription.

#### `GET /api/v1/subscriptions/{id}/attachments`

List them. Responses: `200`, `401`, `403`.

#### `POST /api/v1/subscriptions/{id}/attachments`

Attach a document. `multipart/form-data`:

| Part | Notes |
| --- | --- |
| `file` | **Required.** PDF, PNG, JPEG, WebP or GIF. Type determined from contents; filename and declared content type are ignored. |
| `period_date` | Optional `YYYY-MM-DD`. The billing period this document covers. |

```bash
curl -X POST \
     -H "Authorization: Bearer rnv_..." \
     -F "file=@invoice-2026-09.pdf" \
     -F "period_date=2026-09-01" \
     https://your-instance.example/api/v1/subscriptions/12/attachments
```

Responses: `201`, `400`, `401`, `403`, `422`.

#### `GET /api/v1/subscriptions/{id}/attachments/{attachmentId}`

Download the file. Returns the bytes with a `Content-Disposition` header, not
JSON. Responses: `200`, `401`, `403`, `404`.

#### `DELETE /api/v1/subscriptions/{id}/attachments/{attachmentId}`

Delete it. Responses: `204`, `401`, `403`, `404`.

---

### Categories and tags

#### `GET /api/v1/categories`

The household's categories. Responses: `200`, `401`, `403`.

#### `POST /api/v1/categories` · `PUT /api/v1/categories/{id}`

Create, or rename and recolour. Body:

```json
{ "name": "Streaming", "colour": "#e0a355" }
```

`name` is required, maximum 60 characters. `colour` is nullable and must match
`^#[0-9a-fA-F]{6}$`.

Responses: `201`/`200`, `401`, `403`, `404` (on `PUT`), `422`.

#### `DELETE /api/v1/categories/{id}`

Responses: `204`, `401`, `403`, `404`.

#### `GET /api/v1/tags`

The household's tags. Responses: `200`, `401`, `403`.

**Tags have no create endpoint.** They are created by naming them in a
subscription's `tags` array, exactly as in the web form, so that one place
decides how they are deduplicated.

#### `DELETE /api/v1/tags/{id}`

Responses: `204`, `401`, `403`, `404`.

---

### Calendar feed

#### `GET /api/v1/calendar.ics?token=rnv_...`

Renewals, trial conversions and cancellation deadlines as an iCalendar
document, for subscribing in a calendar client.

This is **the only endpoint that takes its token in the query string**, and it
is a separate middleware rather than a condition inside the shared one — so a
route cannot end up accepting URL credentials by being added in the wrong
place. A calendar client fetches a URL on a timer for ever and cannot send an
`Authorization` header; the feed pays for that in the only currency available:

- It accepts **read-only tokens exclusively.**
- A **write-capable token is refused with `403`, not downgraded.** A credential
  that can change data does not belong in a subscription URL, and quietly
  accepting one teaches the operator that it is fine to put it there.

Responses: `200` (`text/calendar`), `401`, `403`.

---

## Schemas

### SubscriptionInput

The writable half of a subscription. Required: `name`, `price_minor`,
`currency`.

| Field | Type | Notes |
| --- | --- | --- |
| `name` | string | Max 150. |
| `notes` | string, null | Max 5000. |
| `website_url` | string, null | Max 300. A bare host is accepted and stored as https. |
| `price_minor` | integer | Minor units. £19.99 is `1999`. |
| `currency` | string | ISO 4217 code. |
| `subscription_type` | enum | `recurring` (default), `one_off`, `lifetime`. |
| `billing_cycle` | enum, null | `weekly`, `monthly`, `quarterly`, `yearly`, `custom_days`. |
| `cycle_days` | integer, null | 1–3650. Required when `billing_cycle` is `custom_days`. |
| `next_payment_date` | date, null | Required for a recurring subscription that is not a trial. |
| `start_date` | date, null | |
| `notice_period_amount` | integer, null | 0–3650. |
| `notice_period_unit` | enum, null | `days`, `weeks`, `months`. |
| `reminder_days` | int[], null | Max 6 items, each 0–365. Three states — see [Conventions](#conventions). |
| `is_trial` | boolean | Default `false`. |
| `trial_end_date` | date, null | |
| `converts_to_price_minor` | integer, null | |
| `converts_to_billing_cycle` | enum, null | As `billing_cycle`. |
| `converts_to_cycle_days` | integer, null | |
| `is_active` | boolean | Default `true`. |
| `category_id` | integer, null | |
| `owner_user_id` | integer, null | Must be a household member. Ignored in ISOLATED mode, where a user may own only their own rows. |
| `payer_user_id` | integer, null | |
| `tags` | string[] | Max 25, each max 50 chars. Created by name if they do not exist. |

### Subscription

Everything in `SubscriptionInput`, plus these read-only fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | |
| `anchor_day` | integer, null | The day of the month billing returns to after a short month. |
| `category_name` | string, null | |
| `split_mode` | string | Shared-cost splits are read here, written in the web interface. |
| `usage_count` | integer | |
| `usage_rating` | integer, null | |
| `logo_path` | string, null | Set it with the logo endpoints. |
| `monthly_minor` | integer, null | Equivalent monthly cost, minor units. |
| `yearly_minor` | integer, null | Equivalent yearly cost, minor units. |
| `next_charge_date` | date, null | The trial's conversion date while a trial runs; the next payment date otherwise. |
| `cancellation_deadline` | date, null | The last day to cancel and avoid the next charge. |
| `days_until_next_payment` | integer, null | |
| `created_at` | date-time | |
| `updated_at` | date-time | |

### Page

Returned as `meta` on a list response.

| Field | Type |
| --- | --- |
| `page` | integer |
| `per_page` | integer |
| `total` | integer |
| `total_pages` | integer |

### Identity

Returned by `GET /me`. Fields: `user` (`id`, `email`, `display_name`,
`is_instance_admin`), `household_id` (nullable), `role` (`owner_admin`,
`editor`, `viewer`, or null), `isolation_mode` (`shared`, `isolated`),
`permissions` (array of strings).

### Category · Tag · Attachment

| Schema | Fields |
| --- | --- |
| `Category` | `id`, `name`, `colour` (nullable) |
| `Tag` | `id`, `name` |
| `Attachment` | `id`, `subscription_id`, `filename`, `mime_type`, `size_bytes`, `period_date` (nullable date), `created_at` |

---

## Not in version 1

**Shared-cost splits, scheduled price changes and the usage counter have no
endpoints.** All three are editable in the web interface. They are absent here
on purpose rather than by oversight.

Each is a sub-resource with rules of its own — a split has to total its shares,
a scheduled price has an effective date that interacts with the price history,
a usage count has a period it is measured over. Giving each a half-considered
endpoint now would fix a shape that becomes awkward to change once clients
depend on it.

They **read** as part of a subscription — `split_mode`, `usage_count`,
`usage_rating` — and are **written** through the web interface until a later
version describes them properly.

Also outside the API: budgets, price history, notifications, import, backup and
restore, and all instance administration.
