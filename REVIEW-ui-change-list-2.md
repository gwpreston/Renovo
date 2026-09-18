# Review — UI change list (second set)

Assessment of six requested changes, before any of them are made. Same method
as `REVIEW-ui-change-list.md`: each item records what it actually touches, what
the request assumes that the codebase does not support, and what a correct
change would cost — including the named test, because CLAUDE.md requires one
with every change and "this is small" is not an estimate until the test is
named.

**Status: all seven were approved and are now implemented** — see "What was
applied" at the foot of this file. The two questions were answered: reissue
rotates (the old secret dies), and the light page is a deeper neutral rather
than a dark one. The review text above each item is left as it was written,
before the work, so the estimates can be read against what actually happened.

**Phase check:** items 1, 2, 5 and 6 are Phase 14 polish and need no scope
conversation. Item 3 (token reissue) is a **new capability**, not polish — it
is the one that may need a scope nod. Item 4 is a bug fix. The sample data
(item 7) is dev tooling and sits outside the phase gates either way.

## Verdict in one line each

| # | Item | Verdict |
|---|---|---|
| 1 | Saved views below Category spending | Do it — but it is a *cross-column* move, and it is where `3cad406` regresses |
| 2 | "Paused / Inactive" card | Do it — the figure does not exist today and needs a new scoped count |
| 3 | Token "reissue" button | Do it — but decide whether the old secret dies (Q1) |
| 4 | "Open on" only on login | **Confirmed bug.** Four entry points, not one |
| 5 | Searchable tags field | Do it — **not** with Select2. The server side already works |
| 6 | Light-mode background | Premise is wrong; the real defect is adjacent (Q2) |
| 7 | More sample data for last year | Do it — but pick a mechanism first; neither existing one fits |

---

## 1. Move "Saved views" below "Category spending"

### What it actually touches

The two cards are not siblings. `templates/subscriptions/index.twig:107`
renders saved views inside `.subscriptions-main`; `_categories.twig` is included
at line 163 inside `<aside class="subscriptions-side">`. So "below Category
spending" is a move **across columns**, not a reorder within one.

### The constraint the request does not state

`.subscriptions-layout` (`assets/css/screens.css:711-726`) is two columns only
above `1100px`. Below that it is a single column and the aside stacks *after*
main — so on a phone the saved-views card moves from just under the filters to
below the list, the two expiring sections and the trials. That is a real
regression in reachability for the narrow layout Phase 14 exists to check. If
that is not wanted, the card has to move on wide screens only, which means two
render sites and a `hidden` rule rather than one relocation.

### The correctness trap

`index.twig:127-138` is explicit about it: the hidden `#saved-view-query` field
is what "save this view" stores, and `_list.twig` sends an **out-of-band
replacement aimed at that id** on every filter, sort and page request. The
`oob_query: false` flag on the include at line 156 exists precisely because this
page renders the real field. Commit `3cad406 Fix subscriptions layout and saved
views` fixed this exact interaction. Moving the markup is where it breaks again
— the id must survive the move, and the OOB swap must still find it.

### Cost

One template edit (or two, plus a CSS rule, if the narrow-screen position is to
be preserved). No service, repository or migration change. No new strings.

**Test:** extend `tests/Functional/SubscriptionsScreenTest.php` — assert the
saved-view query field is still present exactly once after the move, and that
filtering still updates it. Ordering assertions in a functional test are brittle;
assert the field and the OOB behaviour, not the DOM order.

---

## 2. New "Paused / Inactive" card with a figure

### The figure does not exist

`StatsService::dashboard()` calls `allForStats($scope)`, whose `$activeOnly`
defaults to `true` (`SubscriptionService.php:409`), and then counts only rows
where `isActive` is true (`StatsService.php:85-88`). There is no inactive count
anywhere in the array the strip is built from.

`SubscriptionRepository::findAllForStats($scope, false)` (line 193) *would*
return the inactive rows. **Do not re-point the dashboard at it**: every figure
on the dashboard, the statistics screen and the subscriptions strip is derived
from that one fetch, and widening it silently changes all of them.

### The cheaper, honest route

A scoped `COUNT(*) WHERE is_active = false` on `SubscriptionRepository`,
surfaced through `SubscriptionService`, consumed in
`SubscriptionScreenService::strip()`. That keeps non-negotiable #2 (no raw SQL
outside repositories) and #4 (the count goes through `scopedWhere`, so ISOLATED
mode hides other people's paused rows without the card remembering to ask).

### Two things the request leaves open

- **The grid takes it.** `.metric-grid` is
  `repeat(auto-fit, minmax(min(100%, 13rem), 1fr))` (`screens.css:94-98`), so a
  fourth card flows without a rule change. It will wrap 3-then-1 at some widths;
  that is worth looking at rather than assuming.
- **"With a figure" — which figure?** The other three strip cards each carry a
  number plus a muted note. Default assumption, stated so you can overrule it:
  the count, plus a muted note giving what those paused subscriptions would cost
  per year if resumed. That second number is the one that makes the card worth
  having, and it reuses `partials/spend.twig` and the per-currency rule rather
  than inventing a blended total.

### Cost

One repository method, one service passthrough, one strip key, one new
partial-or-block in `_strip.twig`. New strings in `translations/en.php` **and
every other locale** — `i18n:check` fails on missing *or* extra keys.

**Test:** `tests/Functional/SubscriptionsScreenTest.php` for the figure
appearing, plus an isolation assertion (a paused subscription owned by another
member must not be counted in ISOLATED mode) — that is the permission test
CLAUDE.md requires for anything touching data.

---

## 3. "Reissue" button on each API token

### There is no rotate today

`ApiTokenService` has `issue()` and `revoke()` and nothing between them
(`src/Service/ApiTokenService.php`). `ApiTokenRepository` likewise — `create`,
`revoke`, `touch`, two finders. Reissue is a new service method that revokes the
old row and issues a new one, reusing the existing `FLASH_NEW_TOKEN` one-shot in
`ApiTokenController` so the new secret is displayed exactly once and never
survives a refresh.

### Four constraints the request does not state

1. **Carry-over.** Name, abilities and expiry must come from the old token, or
   "reissue" is just "create" with extra steps.
2. **`issue()` rejects a past expiry.** It throws on
   `$expiresAt <= $this->clock->now()` (line 78). Reissuing an already-expired
   token must therefore either drop the expiry or be refused outright. Proposal:
   offer reissue only on a token that is neither revoked nor expired, matching
   the existing `{% if not token.isRevoked() %}` guard at `tokens/index.twig:90`.
3. **Ownership.** `revoke(User $user, int $tokenId)` is scoped by user id.
   Reissue must be scoped the same way, or one user can rotate another's token.
4. **New POST route**, CSRF field, and a confirm prompt matching the revoke
   button's — reissue invalidates a credential something is probably using.

### What it does *not* trigger

This is a web route on `/settings/api-tokens/...`, not an API endpoint, and it
introduces no new `Permission` case — the `ApiTokenController` docblock explains
why these routes name none. So **no `openapi.yaml` and no `docs/api.md` change**,
and the two coverage tests stay green untouched. Worth saying, because the "New
API endpoint" rule in CLAUDE.md looks like it applies here and does not.

### Cost

One repository method or a reuse of `create` + `revoke` in a transaction, one
service method, one controller action, one route, one button, one audit action
(or reuse of the two existing ones — a reissue is honestly recorded as a revoke
followed by an issue). New strings in every locale.

**Test:** a new case in whichever suite covers `ApiTokenService` — old secret
stops authenticating, new secret authenticates, name/abilities/expiry carried
over, and another user's token cannot be reissued.

---

## 4. "Open on" should apply only to the login flow

### Confirmed bug, and worse than reported

`DashboardController::index()` (lines 38-48) honours the landing preference on
**every** request to `/`. The dashboard nav item's `href` is `/`
(`NavigationService::primaryItems()`), so for any user whose preference is not
Dashboard, **the dashboard is unreachable from the navigation at all** — the rail,
the phone tab bar and the drawer all point at a route that bounces them away.
The request is right and the fix is the one it describes.

### The constraint the request does not state: four entry points

There is no single "after login" hook. A fresh session can begin at any of:

- `src/Controller/Auth/LoginController.php:86` — password login
- `src/Controller/Auth/TwoFactorController.php:210` — TOTP / recovery code
- `src/Controller/Auth/TwoFactorController.php:185` — WebAuthn second factor (JSON)
- `src/Controller/Auth/PasskeyLoginController.php:113` — passkey login (JSON)

All four resolve a `$next` that defaults to `/`. The landing preference has to
be applied in one shared place all four call, or three of the four paths will
quietly keep ignoring it.

### The precedence rule that is the whole correctness of the change

`AuthenticationMiddleware::redirectToLogin()` (lines 72-82) stores the requested
path as `?next=` for any GET that is not `/`. So a user who deep-links to
`/budgets`, is bounced to login and signs in must land on `/budgets` — **not** on
their "Open on" page. The preference applies *only* when the safe target
resolves to `/`. Get that backwards and you break deep linking for everyone.

The existing `needsSubscriptionAccess()` + `ViewSubscriptions` check must move
with the redirect, not be left behind — a Viewer whose preference is Budgets
still has to land somewhere they may look.

### The test that already exists

`tests/Functional/PersonalisationTest.php:268` —
`testTheLandingPreferenceRedirectsTheRoot` asserts exactly the behaviour being
removed. The right move is to **relocate** that assertion to the login flow, not
delete it, and to add the two cases that make the new rule real: `/` after
sign-in goes to the preference, and `?next=/budgets` beats the preference.
`testTheDashboardStillRendersWhenItIsTheChosenLanding` (line 278) stays as-is and
becomes the weaker of the two guards.

### Cost

One shared resolver (on `LandingView` or a small service), four call sites, the
removal of the block in `DashboardController`, three test cases. No strings, no
migration.

---

## 5. Searchable / creatable tags field

### The server already does this

This is the good news and it changes the shape of the work.
`TagRepository::resolveOrCreate(Scope $scope, array $names)` (line 52) already
takes names, resolves the ones that exist and creates the ones that do not, and
`SubscriptionController:215` already round-trips the field as a comma-separated
string. Choosing an existing tag and creating a new one are, on the server, the
same call that runs today.

### The one server change needed

`SubscriptionController::formData()` (lines 297-317) passes `categories`,
`members`, `currencies`, `cycles`, `types` and `notice_units` — and **no tags**.
The add/edit form therefore has no list of existing tags to offer. One line:
`'tags' => $this->tags->all($scope)`. `TagService::all()` already exists and is
already scoped; the index screen uses it at line 83.

### Why not Select2

Three reasons, in order of how hard they are to argue with:

1. **Non-negotiable #8.** Nothing may be loaded from a third-party host. Select2
   would have to be npm-installed and vendored through Vite —
   `assets:offline-check` scans templates, build sources *and* build output, so a
   CDN `<script>` fails CI. That is survivable but it is not "just add Select2".
2. **It drags jQuery** into a bundle that has none, for one field on one form.
   CLAUDE.md's front-end rule says a large library goes behind a dynamic
   `import()` so it is a chunk rather than weight on every page.
3. **It must degrade.** The field is a plain `<input type="text">` today and
   works with JS off. A progressive enhancement over that input keeps that
   property; a widget that replaces it does not.

### Two house-consistent alternatives

- **Native `<datalist>`** — zero JavaScript, zero dependency, gives type-ahead
  over existing tags immediately, and free-typing a new name still works. It does
  not give multi-select chips, and browser styling of the dropdown is not ours to
  control. This is the cheapest thing that satisfies the literal request.
- **A small vendored combobox behind a dynamic `import()`**, following the
  `assets/js/charts.js` precedent — chips, keyboard navigation, ARIA combobox
  semantics, and it enhances the existing input rather than replacing it. More
  work, and it has to meet the Phase 14 keyboard and focus-visibility bar.

Recommendation: the datalist first, because it is one template change plus one
controller line and it may simply be enough. Escalate to the combobox if
multi-select chips turn out to be the point.

### Cost

Datalist route: one controller line, one template block, one new string. Combobox
route: add a JS module, an icon or two in `assets/js/icons.js`, CSS, and an
accessibility pass.

**Test:** a functional assertion that the existing tags reach the form, plus the
existing create/edit round-trip tests staying green — the wire format is
unchanged, which is what makes this low-risk.

---

## 6. Light-mode background colour

### The premise is not right

`assets/css/tokens.css:79` already sets `--bg: #f4f5f7` for light, and
`assets/css/base.css` already paints `body { background: var(--bg) }`. Light mode
is not missing a background colour.

### What is actually wrong

`--surface: #ffffff` and `--surface-raised: #ffffff` (lines 80-81) on a `#f4f5f7`
page. That is a 2% separation between a card and what it sits on. With
`--shadow` set to a very light `rgba(16, 24, 40, 0.06)`, cards do not read as
cards — the page reads as one undifferentiated white sheet, which is almost
certainly what prompted "set a background colour". The fix is to increase the
*separation*, not to add a background that is already there.

### The proposal

Deepen `--bg` to a warmer, more definite neutral and leave `--surface` white, so
cards lift off the page. A concrete starting value: **`--bg: #e8eaee`** — still
unambiguously a light theme, roughly three times the current separation from
white. `--surface-sunken` and `--surface-hover` (both `#eef0f3`) then need to move
with it or they will sit *lighter* than the page they are meant to recede into.

### The constraint Phase 14 makes explicit

`--text-muted: #5b6472`, `--border-control: #7d8794` and `--shadow` are all tuned
against the current surfaces, and PHASE.md names "secondary text and every state
checked against the surface they sit on" as a gate for this phase. Changing `--bg`
means re-checking muted text, control borders, the meter fills and every
`*-bg`/`*-text` pair against the new value — not eyeballing one screenshot.

---

## 7. More sample data — last year, a budget, categorised subscriptions

### Two mechanisms exist and neither fits

- **`DemoSeedService`** (behind `php bin/console demo:seed`) builds a whole
  separate `demo@renovo.local` account and household, six subscriptions, three
  categories, no budget. Its six fixtures all start `-1 year` but every one is
  *currently* active with a near-future due date.
- **`seeds/DemoDataSeeder.php`** (Phinx) inserts six categories into the first
  existing household and deliberately seeds nothing else — its docblock explains
  that seeding users means seeding password hashes.

"My development household should show a year of history, a budget and
categorised subscriptions" matches neither. Pick one before building:

1. **Extend `DemoSeedService`** — richest data, correct by construction (it
   writes through the real services, so scoping, validation and price history all
   apply), but it lives in its own household, so it does not populate *yours*.
2. **A new `dev:seed` command** targeting the signed-in/first household — what
   the request seems to want, and the one that needs care: it must refuse to run
   against a database that already has real data, and it must never run on
   migrate.

### The trap: "last year" is reconstructed, not stored

`StatsService` is explicit that the year-over-year comparison keeps no ledger of
payments — it rebuilds spend from each subscription's start date, its billing
cycle, and **price history** saying what it cost on each of those dates. So
backdating start dates alone renders an empty or flat comparison. Convincing
sample data needs, per subscription: a backdated `start_date`, a
`recordInitialPrice` row at that date, and at least one `PriceHistoryService`
change part-way through the year so the price-trend and comparison views have
something to show. A few ended/paused subscriptions would also give item 2's new
card a non-zero figure to display.

A budget is one `BudgetService::create()` call, which is straightforward — but it
only looks right if the subscriptions it measures are categorised, which is the
third part of the request and comes free once the fixtures name categories.

### Cost

The largest item on this list by some margin, and the only one with no user-facing
surface. Roughly: a fixture set with a year of dates, price-history writes per
subscription, a budget, a new console command with a safety guard, and a line in
`bin/console`'s help output.

**Test:** the seeder is dev tooling, so the test that earns its keep is a smoke
test that the command runs and the resulting household renders the stats
comparison with two non-zero years — that is the thing that silently fails.

---

## Two questions to settle before building

**Q1 — Does "reissue" kill the old secret?** The literal reading of "generates a
new token without revoke" is *"without my having to hit revoke first"*. But
whether the old credential keeps working afterwards is genuinely unspecified, and
leaving it live means every reissue multiplies the credentials that can reach the
account. **Recommendation: true rotation** — the old secret stops working the
moment the new one is shown, and the revoke button stays exactly as it is for the
case where you want the token gone and not replaced. Confirm before I build it.

**Q2 — "A dark background colour in light mode"** is self-contradictory as
written, so I have not assumed it. **Recommendation:** a deeper light neutral
(`#e8eaee` as a starting point) with cards left white, which is what fixes the
actual defect described in item 6. If you genuinely meant a dark page, the dark
palette already exists and is selectable under Settings → Appearance — say so and
I will leave light alone.

---

## What this costs together

Items 1, 4 and 5 (datalist route) are small and independent. Item 2 is small but
touches the scoping layer and so carries a permission test. Item 3 is a new
capability and wants the scope nod. Item 6 is one token change plus a contrast
re-check across every screen, which is the Phase 14 gate rather than an extra.
Item 7 is the big one and is worth doing **first**, because items 2, 6 and the
whole of Phase 14's "view every screen in three themes" are much easier to judge
against a household that actually has a year of data in it.


---

# What was applied

All seven, with the gates green: `phpcs`, `phpstan` (level 6), 1,727 tests,
`i18n:check` and `assets:offline-check`.

## Where the review was wrong

Three things the review got wrong, found while building:

1. **The sample data had a third home.** The review said `DemoSeedService` and
   `seeds/DemoDataSeeder.php` were the only two mechanisms and neither fitted.
   `bin/dev-setup.sh --with-sample-data` is the third, and it is exactly the one
   the request wanted: it drives the real web forms with curl against *your*
   development household. That is where item 7 went.
2. **A `<datalist>` cannot do the job.** The review offered it as the cheap
   option. It matches suggestions against the *whole* input value, so on a
   comma-separated field it stops working after the first tag. The combobox was
   not the escalation — it was the only option that works.
3. **The price-history endpoint only schedules.** `/price-changes` refuses a
   date at or before today, so backdated history cannot be written through it.
   It does not need to be: `recordInitialPrice` dates the opening price from the
   subscription's start date, so a backdated start *is* the historical record.

## What each item became

**1 — Saved views below Category spending.** Moved into `.subscriptions-side`,
after the category widget. `#saved-view-query` survived the move, which is the
regression `3cad406` fixed and this is where it would have returned. Two
comments that described the old position were corrected rather than left to rot.

**2 — Paused / inactive card.** `SubscriptionRepository::findPaused()` →
`SubscriptionService::paused()` → `SubscriptionScreenService::strip()`. The
first attempt was a SQL `COUNT` + `SUM`, which was wrong: a yearly figure is the
billing cycle applied to the price, which `BillingCycle` knows and the database
does not, so a `SUM(price_minor)` would add a weekly row to a yearly one. The
rows are hydrated and tallied in PHP instead. Per-currency subtotals, no blended
total — this is a "what if you resumed these", and putting it through the
exchange-rate combination would give it more authority than it has earned.

**3 — Token reissue.** True rotation: `ApiTokenService::reissue()` revokes then
issues, carrying name, abilities and expiry across. Offered only on a token that
is neither revoked nor expired, because `issue()` refuses a past expiry and
reviving a dead token would quietly extend its life. Scoped by user id in the
statement, so one user cannot rotate another's.

**4 — "Open on" only on login.** `SignInService::landingFor()`, called from all
four post-auth entry points. A deep link beats the preference: the only target
it replaces is `/`. `DashboardController` lost the redirect *and* its now-unused
`PermissionService` dependency. `testTheLandingPreferenceRedirectsTheRoot` was
relocated, not deleted — it is now three tests, including the deep-link
precedence that is the whole correctness of the change.

**5 — Searchable tags.** One controller line (`formData()` now passes the
household's tags) plus `assets/js/tag-field.js`: an ARIA 1.2 combobox, no
dependency, enhancing the existing text input rather than replacing it, so the
field still works with the script blocked. The catalogue keeps the remove
button's sentence — it is rendered with a marker where the tag name goes and the
script substitutes, rather than concatenating a word and a name in JavaScript.

**6 — Light-mode background.** `--bg` `#f4f5f7` → `#e8eaee`, which roughly
doubles a white card's separation from the page. Three tokens had to move with
it, and `DesignTokensTest` is what found two of them:

| Token | Was | Now | Why |
|---|---|---|---|
| `--surface-hover` / `--surface-sunken` | `#eef0f3` | `#dde0e6` | They were *lighter* than the new page, so depth read backwards |
| `--accent` | `#097a70` | `#086f66` | 4.33:1 on the new page — a link in a card's margin is read against the page |
| `--border-control` | `#7d8794` | `#767f8c` | 3.02:1 is over the line by a rounding error |
| `--warning` | `#b4430f` | `#a83f0e` | 4.23:1 on the new hover grey. **Caught by the test, not by me** |

Two of those values are also written as fallbacks in `assets/js/spend-chart.js`,
for the case where the custom property cannot be read, and both were stale after
the move. **No gate covers this**: `DesignTokensTest`'s colour-literal check
reads `assets/css/*` only, so a colour literal in JavaScript is unguarded. The
donut's `--series-*` literals are untouched and correct — `--series-4` happened
to equal the old `--warning` and no longer does, which is exactly the trap.
A literal-colour check over `assets/js/*` would close this; it was not added,
being outside what was asked for.

**7 — Sample data.** `bin/dev-setup.sh --with-sample-data` was extended to
create five categories, eleven subscriptions (start dates 14–30 months back, so
the twelve-month comparison has two real years), three scheduled price changes,
a trial, two paused rows and two budgets — one overall, one by category.
**This block has not been executed** — see below.

## What was *not* verified

The running container serves `src/` and `templates/` from its **image**; only
`public/` is mounted. So the browser check confirmed the new palette (which
lives in `public/build/`) and that `/` no longer redirects, but it showed
two-hour-old templates for everything else. Those are covered by the test suite,
which runs against the working tree — including document-order assertions that
saved views sits after the category widget, and that the paused count respects
ISOLATED mode.

To see it all in a browser, rebuild the app image or start the dev overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up
```

**Item 7 has never run.** What was checked is `bash -n`, and both `sed`
parsers — the category-id lookup and the `LAST_ID` round-trip — against captured
fixtures of the real markup, including the two-line `<option>` the template
emits and the `selected` variant. What was *not* done is running the block
itself: the POSTs to `/categories`, `/budgets` and `/subscriptions/{id}/money`,
and the empty `category_id=` on the overall budget, have not been exercised
against a live instance. Running it needs `--reset`, which destroys the
development database, so it was left for you to decide rather than done
unilaterally.

It fails softly, which is the thing to watch: a `category_id` lookup that
returns nothing posts `category_id=` and quietly creates an uncategorised
subscription. Only the counters (`created $CREATED of 11`) would say so. Worth
one read of the output the first time it runs.

The suite was run against **PostgreSQL only**. CI runs MySQL as well, and the one
new query (`findPaused`) uses the same `Criteria`/`scopedWhere` path as its
neighbours, so it carries no new portability risk — but it has not been executed
against MySQL here.
