# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 9 — the application shell

Every screen sits inside one frame: a fixed left sidebar and a top bar. Building
it once, correctly, is what lets Phases 10–12 be about their own content and
nothing else. The shell is a Twig layout the page templates extend; it holds no
figures of its own, so it has nothing to get wrong about money or scope.

## The sidebar

A fixed 240px rail: the **Renovo mark** at the top, the primary navigation, then
the tools group at the bottom. Each item is a real route that already exists — the
navigation is a new way to reach the application, not new pages. The mark is the
supplied logo, exported to SVG so it stays crisp at the rail's size and in the
collapsed bar, and it recolours with the brand gradient across themes rather than
carrying the wordmark's navy onto a dark surface. Each nav item takes a **Lucide**
icon (bundled by Phase 7, not fetched), the same thin stroke throughout.

| Design label | Existing destination |
| --- | --- |
| Dashboard | the dashboard |
| My Subscriptions | the subscriptions list |
| Analytics | Statistics (with Forecast and year-over-year reachable from it) |
| Billing Calendar | the Calendar |
| Notifications | Settings → Alerts |
| Categories | category management |
| Settings | Settings |
| Profile | the account/appearance area |

The **Upgrade Plan** promotional card is not built. Renovo is self-hosted and has
no plans to upgrade to; a card advertising one would be dishonest furniture. The
space it occupied goes to nothing, or to the theme/quick-add controls that do
belong there.

The active item takes the amber accent — a solid fill or a left border, one
treatment chosen and used consistently — and it is derived from the current route
server-side, so the highlight is right on first paint rather than corrected by
script after load.

## The top bar

Page title on the left, account and search on the right. The **Manage Balance**
pill from the design is dropped for the reason given in Phase 8; the **quick-add**
dialog that already exists takes the prominent-action slot instead, since adding
a subscription is the action this bar should actually offer. Quick-add continues
to load the real subscription form rather than a shortened copy, so there remains
one definition of what a subscription needs.

## Responsiveness

Below 768px the rail collapses. The intended form is a bottom tab bar for the
few most-used destinations with the rest behind a drawer, matching the design's
"collapse to bottom tab bar or hamburger". Whatever the rail can do, the
collapsed form can also do: navigation is links, so a narrow screen loses the
layout and none of the reach.

## Keeping the shortcuts

The existing keyboard shortcuts (`?` for the list, `n` for quick-add, `g` then a
letter to navigate) are wired to the shell so they keep working across the new
navigation — `g d`, `g s`, `g c` still land on the dashboard, subscriptions and
calendar. Each remains a link or button on the page as well, so a browser with
JavaScript off is unaffected.

## Done when

Every existing page renders inside the new shell with its correct active nav
item, on wide and narrow viewports, in both themes; the shortcuts still reach
their destinations; nothing about permissions, money or scope has moved; the
quality gates and `i18n:check` pass. Any new label added to the navigation exists
in `translations/en.php`.
---

## Built — what actually landed

Every item above is done. Notes on the decisions that were open when the phase
started, and the three places this deviates from the design:

- **The logo arrived during the phase.** Phase 8 had recorded that the mark was
  not in the repository and that its gradient stops were PHASE.md's own stated
  values. The supplied `renovo-logo.png` is now `assets/brand/renovo-logo.png`,
  and two things follow from it. The mark is traced to a single even-odd
  outline — under 2kB of path data, in `templates/partials/brand_sprite.twig` as
  an SVG symbol the rail and the top bar both `<use>` — filled with the brand
  gradient through the tokens, so its counters show the page through and it
  recolours across themes rather than carrying the wordmark's navy onto a
  near-black surface. And the gradient stops were **re-sampled from the real
  mark**: every pixel projected onto the 135° axis the gradient runs along, the
  first and last twentieth averaged, giving `#0daa9c` → `#1069bb` in place of
  `#1fae8f` → `#2b74d6`. `--accent` and `--action-*` were re-derived from them
  and `DesignTokensTest` re-checked the contrast, which is how it emerged that
  only the teal end needs darkening to carry white — the real blue stop already
  does at 5.6:1.

- **The page heading moved into the top bar**, which is the one structural
  change the shell forced on every page template. `layout.twig` declares
  `{% block heading %}` and `{% block page_actions %}`; the top bar renders the
  heading as the page's single `<h1>` and the `<title>` is derived from the same
  block, so a page cannot be titled one thing and headed another. Nineteen
  templates gave up their own `.page-header`. Sign-in, registration and the
  setup wizard keep theirs, because there is no shell before there is an
  account; `error.twig` renders one or the other and never both.

- **The design's eight rows were not the whole navigation.** The rail reaches
  thirteen destinations, because the design table has no row for budgets,
  cancel-by, import or the audit log and dropping the only link to them would
  have been a regression dressed as a layout. The design's labels are used where
  it supplies one. Three deviations, each deliberate:

  - **"My Subscriptions" is "Subscriptions".** In SHARED isolation — the default
    — the list is the household's, not yours. A possessive label would be a
    quietly false statement about what the page shows.
  - **Analytics covers two screens.** It points at Statistics and is the active
    item for the forecast as well; the forecast is linked from the statistics
    page, which is what "Forecast reachable from it" means in practice, and the
    `g f` shortcut still goes straight there.
  - **Profile is a deep link, and is never the active item.** There is no profile
    page — what the design describes is the appearance section of Settings — so
    it goes to `/settings#appearance`, and the fragment that distinguishes it
    never reaches the server. Lighting Settings alone is true; lighting both
    would not be.

- **Which item is active is a service's answer, not a template's.**
  `NavigationService` declares each destination once with the paths it claims and
  resolves the most specific claim. The old navigation had the collision baked
  into the template — `/settings` is the start of `/settings/notifications` — and
  needed an explicit `and current_path != …` to work around it. `NavigationTest`
  now asserts exactly one item is active for every path the application serves,
  including `/subscriptions-archive`, which claims nothing because a path that
  merely begins with the same letters is not part of a section.

- **The narrow layout is the same list, split.** `tabs` plus `drawer` equals
  `primary` plus `tools`, asserted rather than maintained by hand. The drawer is
  a `<details>`, so it opens with the bundle blocked, and its summary carries the
  active marker when the page you are on is one of the ones it hides.

- **Quick-add degrades.** The top bar's prominent action is an
  `<a href="/subscriptions/new" data-opens-dialog="quick-add">`: the dialog when
  there is script to open it, the real form when there is not. The click handler
  now only swallows the event if a dialog actually opened, which is what makes
  that true. With quick-add in the bar on every page, the duplicate "Add
  subscription" buttons on the dashboard and the list were removed.

- **Icons are hydrated, not inlined.** The server marks an empty slot; the
  bundle fills it from the tree-shaken Lucide map. The alternative — path data
  in Twig — would be a second copy of the icon set to keep in step by hand. The
  slots are sized in CSS, so nothing shifts when the drawings arrive, and the
  labels beside them carry the meaning for anybody who never gets them.

- **Two strings that were still English in a template** were caught by moving
  the controls they sat in: the forecast's household/share toggle and the budget
  form's heading now come from the catalogue like everything else.

- **The shell was checked against the running application**, not only in tests:
  both themes, the rail at desktop width, and the tab bar and drawer below
  768px, with real data from the demonstration household.
