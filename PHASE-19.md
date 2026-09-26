# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 19 — the shell, rebuilt to the prototype

Every screen sits inside the sidebar ("rail") and the top bar. Phase 9 built that
frame; this phase rebuilds it to the Claude Design prototype, on the tokens,
palettes, fonts and icons Phase 18 put in place. It is still only the frame: it
holds no figures of its own beyond three small, scoped reads (named below), and
it changes no page's content. The page rebuilds — dashboard, subscriptions,
analytics and the rest — are the phases after this one.

## Depends on

- **Phase 18** — rail tokens (`--rail-*`), the accent rule, the `icon()` Twig
  function, the component vocabulary, server-rendered `data-theme` /
  `data-palette`.
- **Phase 9** — `layout.twig`, the `heading` / `page_actions` blocks, the single
  `<h1>` in the top bar, `NavigationService` and `NavigationTest`, the tab-bar and
  drawer split, quick-add loading the real form.
- **Phases 2 and 6** — the exchange-rate cache (for the rates chip) and global
  search on the subscriptions list (for the search box).

Before starting: tick Phase 18's status list and record its deviations in
`PHASE-18.md`, as every earlier phase has, so this phase builds on what was
built rather than what was planned.

## In scope this phase (build ONLY these)

### A. The rail

Top to bottom, as in the prototype:

1. **Brand** — the mark (in `--logo-tile` on dark-rail palettes), "Renovo", and
   the catalogued tagline "Household spend".
2. **Household label** — house icon, the household's name, and
   "N members · you're {role}" beneath. **A label, not a control**: no chevron,
   no menu (decision 16 — no household switcher). The role is the viewer's own
   membership role, catalogued, including Contributor.
3. **Main navigation** — Dashboard, Subscriptions, Analytics.
   Subscriptions carries a **count badge**: active subscriptions the viewer can
   see, from a scoped count through the repository layer, so ISOLATED shows the
   viewer's own rows plus splits they share, never the household's total.
4. **"Household tools"** (catalogued group heading) — Budgets, Calendar,
   Members & roles, Notifications, Settings.
5. **Add subscription** — opens the existing quick-add dialog (the real form);
   with no script it is a link to `/subscriptions/new`. Styled as a
   **secondary** rail button, not an accent fill, because the top bar's
   "Add new" is the one primary action on every screen (Phase 14's rule).
6. **User card** — avatar (initials fallback), full name, email; the whole card
   links to `/profile`, and a sign-out control sits beside it so signing out
   stays one click from anywhere.

Active item: `--rail-active` fill, `--rail-ink` text, 3px `--accent` left
border, icon in `--rail-accent` — decided server-side by `NavigationService`,
correct on first paint. Status is never colour alone: the active link also has
`aria-current="page"`.

### B. Where every existing destination goes

The prototype's rail has fewer items than Phase 9's thirteen. Nothing loses its
only route — every destination is either in the rail or claimed by one:

| Destination | Reached from | Active item |
| --- | --- | --- |
| Dashboard | rail | Dashboard |
| Subscriptions, cancel-by (`/cancellations`) | rail; cancel-by linked from the Subscriptions page | Subscriptions |
| Statistics (`/stats`), Forecast (`/forecast`) | rail | Analytics |
| Budgets | rail | Budgets |
| Calendar | rail | Calendar |
| Members & roles | rail | Members & roles |
| Notification preferences (per-user, decision 13) | rail | Notifications |
| Settings, Categories, Payment methods, Import, Audit log, API tokens | rail → Settings; the existing Settings page gains a row of links to each until the Settings rebuild phase gives them tabs | Settings |
| Profile | user card | the user card (shown active on `/profile`) |

**Members & roles** points at the Phase 15 member screen if it is built. If
Phase 15 is not yet built, the item is **omitted** rather than pointed at a page
that does not exist, and Phase 15 adds it — a seam, not a stub.

### C. The top bar

- **Page title and subtitle.** The title is the existing `heading` block (still
  the page's only `<h1>`, still the source of `<title>`). A new
  `{% block page_subtitle %}` holds the short line beneath; this phase gives each
  existing page a **catalogued static** subtitle ("Limits against projected
  spend", "Renewals, trials & deadlines"…). Subtitles that carry figures
  ("12 tracked · £182/mo") belong to each page's own rebuild, where the
  per-currency rule can be honoured. Hidden below 900px.
- **Search** (≥1100px) — a GET form submitting `q` to the subscriptions list,
  using the list's existing search, so it works with JavaScript off.
- **Rates chip** — base currency and the date of the last successful rate
  refresh ("GBP · rates 23 Sep", date via ICU). Three states: fresh; **stale**
  (older than the cache TTL, warn badge); **unavailable** (no rates, warn badge,
  "rates unavailable"). Links to the rate settings. Read from the existing
  rate cache — no fetch is ever triggered by rendering it.
- **Theme toggle** — switches the account between light and dark and saves it
  (CSRF-protected POST, htmx swap of the root attributes; a plain form submit
  without script). An account on "system" moves to the opposite of what it is
  currently showing. Any member, including a Viewer, can set their own.
- **Bell** — a **link**, not an inbox (decision 6), to the Calendar, labelled
  "What's coming up". It shows a dot when a renewal, trial conversion or cancel-by
  deadline falls within the near window (`CancellationService::URGENT_DAYS`),
  reusing that query. The dot has a text equivalent for screen readers.
- **Add new** — the primary accent button (`--accent` fill, `--accent-ink`
  text), opening quick-add; a link without script. It replaces Phase 9's
  prominent-action slot.

### D. Narrow screens (below 768px)

- The rail is replaced by a **bottom tab bar**: Home, Subs, **Add** (the raised
  accent button), Analytics, More.
- **More** opens a sheet listing Budgets, Calendar, Members & roles,
  Notifications, Settings and Profile. It is a `<details>`, so it opens with the
  bundle blocked, and its summary carries the active marker when the current
  page is one it holds.
- The household label and the user card appear at the top of the More sheet.
- Tabs + More = every destination, asserted by test rather than kept in step
  by hand (the Phase 9 invariant, carried over).

### E. Keyboard shortcuts

Every existing shortcut keeps working: `?` for the list, `n` for quick-add, and
`g` then a letter (`g d`, `g s`, `g c`, `g f`, `g t` …). Each still has a link or
button on the page. No new shortcuts are added in this phase.

## Explicitly out of scope (leave clean seams, do NOT stub)

- Page content rebuilds: dashboard A/B, subscriptions, analytics, budgets,
  calendar, members, profile, settings tabs — later phases.
- A household switcher (decision 16), an in-app notification inbox (decision 6).
- Figure-bearing subtitles (each page's own rebuild).
- The data-model additions ("Only payer", member budgets, Plan, cancelled
  trials, price-change alert) — the next phase.

## Data-model changes

**None.** No migration. The theme toggle writes the theme column that already
exists; the badge, the household label and the bell dot are reads.

## New / changed files (indicative)

- `templates/layout.twig`, `templates/partials/rail.twig`, `topbar.twig`,
  `tabbar.twig` — rebuilt.
- `src/Service/NavigationService.php` — new item list, groups, the user card as
  a claimable destination, Members omitted when its route does not exist.
- `src/Service/ShellService.php` (new) — assembles the rail and top-bar context
  (household label, subscription count, rates-chip state, bell dot) from
  existing services, so no controller or template computes them.
- `src/Controller/AppearanceController.php` (or the existing preference
  controller) — the theme-toggle POST.
- `templates/settings/index.twig` — the interim row of links.
- `translations/en.php` — group heading, tagline, subtitles, chip states,
  role phrase, bell label, More.

## Decisions & assumptions (confirmed 2026-09-24)

- **Top-bar "Add new" stays secondary** (ink fill), as Phase 14/18 set it: the
  one accent button on a screen is that page's own action. The rail's "Add
  subscription" is secondary too. "No page renders more than one accent-filled
  button" still holds. *(Overrides section C's "primary accent button".)*
- **The bell links to the Calendar.** Its dot lights for a **trial conversion
  or a cancel-by deadline** inside `CancellationService::URGENT_DAYS`, not for
  plain renewals, which would light it almost permanently.
- **Members & roles adapts:** it links to `/settings/members` for an Owner/Admin
  and to `/household` for anyone else with ViewHousehold. It lights on both
  paths. Phase 15 is built (its routes exist); the item is included.
- **The Subscriptions badge counts active, visible subscriptions**: the list's
  own count with the active filter. Paused rows are left out.
- **Settings gains an interim link row** to Categories, Payment methods, Import,
  Backup, Audit log (for whoever may read it) and API tokens until its own
  rebuild.
- **Rates chip** links to the rate settings for an instance admin. For
  everyone else it is plain status text, because they cannot see that section.
- **Theme toggle is light ↔ dark only.** It extends the existing
  `POST /profile/theme`. Without script, a "system" account's form submits
  `dark`, because the server cannot know what the OS is showing.
- **Brand text is `instance_name`** (default "Renovo"), as the rail shows today.

## Status

- [x] Phase 18 status ticked and deviations recorded
- [x] Rail: brand, household label, main nav with scoped badge, tools group,
      secondary add button, user card with sign-out
- [x] Destination map (section B) applied; interim Settings link row
- [x] Members & roles item (Phase 15 is built; adapts to `/household`)
- [x] Top bar: title + static subtitle block, search, rates chip (3 states),
      theme toggle, bell link + dot, Add new (secondary, per decisions)
- [x] Narrow layout: tab bar + More sheet (`<details>`), tabs + More = all
- [x] Existing shortcuts verified
- [x] New strings in `translations/en.php`
- [x] `composer check`, `i18n:check`, offline guard green on PostgreSQL and MySQL
- [ ] Visual pass in the browser (five palettes × light/dark/system, wide and narrow)

Built as approved, with these decisions:

- **The tab bar's raised Add wears the ink fill**, not the accent, for the
  same reason as the top bar's: on a phone the page's own action is on screen
  beside it.
- **The rail label for the calendar stays "Billing Calendar"**, the existing
  catalogue entry and the page's own heading, rather than the prototype's
  "Calendar".
- **Two cross-links were added to pages** so no destination lost its route:
  Subscriptions → Cancel by (page action), and Members → Household overview
  (page action, for whoever may view it).
- **The user card hides a placeholder address**: a member added without an
  email of their own shows a name only.
- **Contrast:** the shell draws `--rail-accent` as text (tagline, badge) and
  `--rail-muted` on `--rail-tint` (household label). Both pairs are now in
  `ThemeContrastTest`; paper-light and ocean-light `--rail-muted` darkened from
  `#64748B` to `#5E6E84` (4.34 → 4.75:1) to clear them.
- **Rates chip "no fetch" is asserted on pages whose own content fetches
  nothing.** The dashboard still refreshes a stale cache before combining
  currencies (`StatsService::dashboard`, which predates this phase). That is
  the page's work, not the shell's.
- **The theme endpoint takes a `return` path** (local paths only; `//host`,
  `/\`, other hosts and control characters are refused), falling back to the
  Referer. Through htmx it answers 204 with an `HX-Trigger` naming the saved
  theme.

## Definition of done

Every existing page renders inside the rebuilt shell with exactly one correct
active item, on wide and narrow viewports, in all five palettes × light, dark
and system; no destination has lost its route; the shell's three reads are
scoped; every control works with JavaScript off; the shortcuts still land; the
quality gates, `i18n:check` and the offline guard pass on both engines. Then
update `PHASE.md` to the next phase.

## Tests

- **Navigation:** exactly one active item for every path the application serves
  (the user card counts as an item); `/forecast` lights Analytics,
  `/cancellations` lights Subscriptions, `/settings/notifications` lights
  Notifications and not Settings, `/profile` lights the user card.
- **Reach:** every route that had a nav entry in Phase 9 is reachable from the
  rail, the Settings link row or a page that is.
- **Narrow:** tabs + More sheet = the full destination list.
- **Badge scope:** in ISOLATED mode the count excludes other members' rows and
  includes splits the viewer shares; paused rows are excluded; a Viewer sees the
  same count they could list.
- **Household label:** shows the correct catalogued role for Owner/Admin,
  Editor, Contributor and Viewer.
- **Rates chip:** fresh, stale and unavailable states each render; rendering
  triggers no outbound request (count requests through the fake client).
- **Theme toggle:** persists; requires CSRF; allowed for a Viewer; one member's
  toggle does not change another's; the plain-form fallback works.
- **Bell:** links to the Calendar; the dot appears when, and only when, an item
  falls inside the near window.
- **Accessibility:** `AccessibilityTest` still passes on every page — one `<h1>`,
  `main` landmark, skip link, named controls — plus `aria-current` on the active
  item and a text equivalent for the bell dot.
- **One primary action:** no page renders more than one accent-filled button.
