# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 18 — the Renovo theme: tokens, palettes and type

The Claude Design prototype (`Renovo_App2_dc.html`) settles the visual language
that Phase 8 had to guess at from a mock. This phase makes that language the
foundation every later screen builds on: one token set, five palettes in light
and dark, two self-hosted typefaces, a bundled icon set, and a small vocabulary
of base components. It restyles the existing pages through those tokens and
**rebuilds no screen** — the dashboard, subscriptions, analytics, budgets,
calendar, members, profile and settings layouts are later phases, each of which
will consume what this one defines.

Like Phase 8, it is presentation only. No service, figure, permission or scope
changes. Money still reaches a template as integer minor units plus a currency
and is formatted by ICU at the end; every visible string still comes from the
catalogue.

## What this supersedes

The prototype disagrees with earlier re-skin documents in places, and this phase
wins where they conflict. Amend those files when this phase is approved so no
later phase reads a stale rule:

| Earlier rule | Replaced by |
| --- | --- |
| Phase 8: brand/primary/active = the logo gradient; urgency = amber→orange | A flat **accent** per palette (emerald by default) for brand, primary and active; amber is `--warn` only |
| Phase 8: near-black dark surfaces (`#0b0b0e`, `#1a1a20`) | Navy-slate dark surfaces (`#0B1422`, `#111D2E`) |
| Phase 8: Inter for everything, tabular figures on | **Plus Jakarta Sans** for text, **JetBrains Mono** for figures |
| Phase 8: 16px card radius | 12px cards, 14px modals, 8–10px controls, pill badges |
| Phase 7: vendor Inter | Vendor Plus Jakarta Sans + JetBrains Mono instead |
| Phase 9: active nav item takes the amber accent | Active item: `--rail-active` fill + 3px `--accent` left border |
| Phase 14: primary action carries the amber gradient | Primary action = `--accent` fill with `--accent-ink` text, one per screen |

The logo mark and its gradient (`#0daa9c` → `#1069bb`) are unchanged; the
gradient lives on the mark only, not on interface elements.

## Depends on

- **Phase 7** — the Vite pipeline, the manifest-resolving Twig helper, the
  Fontsource vendoring pattern and the CI guard against external asset hosts.
  Everything here is compiled and served locally.
- **Phase 8** — if already built, this phase replaces its colour and type tokens
  in place; if not, build this instead of Phase 8's colour and typography
  sections (its delivery-model and data-reconciliation decisions still stand).
- **Phase 1 / existing Appearance setting** — the per-account
  system/light/dark preference, which this phase extends with a palette.

## In scope this phase (build ONLY these)

### A. One token source, generated CSS

Tokens are authored **once**, in `assets/theme/tokens.json`, and a small build
step (part of the Phase 7 pipeline) generates the CSS for every
palette × theme combination. The same JSON is read by the contrast test (see
Tests), so the values shipped and the values checked cannot drift apart.

Tailwind theme values map onto the CSS custom properties (`surface:
'var(--surface)'` and so on) rather than onto hex values, so switching palette
or theme is an attribute change on the root element — no recompilation, no
per-palette stylesheet.

**Base tokens** (from the prototype):

| Group | Light | Dark |
| --- | --- | --- |
| `--bg` | `#F8FAFC` | `#0B1422` |
| `--surface` / `-2` / `-3` | `#FFFFFF` / `#F1F5F9` / `#E2E8F0` | `#111D2E` / `#172538` / `#22344D` |
| `--border` | `#E2E8F0` | `#1F3048` |
| `--text` / `--muted` / `--faint` | `#0A2540` / `#4A5568` / `#64748B` | `#F1F5F9` / `#A3B1C6` / `#8494AB` |
| `--ink-btn` / `--ink-btn-text` | `#0A2540` / `#FFFFFF` | `#F1F5F9` / `#0A2540` |
| ok (bg / fg) | `#E6F4EA` / `#137333` | 14% emerald / `#6EE7B7` |
| warn (bg / fg) | `#FEF3C7` / `#B45309` | 16% amber / `#FBBF24` |
| bad (bg / fg) | `#FEE2E2` / `#DC2626` → see contrast fixes | 16% red / `#FCA5A5` |
| info (bg / fg) | `#EFF6FF` / `#1D4ED8` | 18% blue / `#93C5FD` |
| Chart series `--s1`–`--s6` | navy, accent, blue, amber, violet, slate | `--s1` flips to `#E2E8F0` |
| Forecast hatch | accent stripes on `--hatch-2` | same, darker stripe |
| Shadow / backdrop | soft shadow / 55% slate | none / 65% black |

Semantic tokens carry meaning, not decoration: **ok** = on track/active,
**warn** = renewing soon / past a warning threshold / paused, **bad** = over
budget / error, **info** = trials. A status is never shown by colour alone —
every badge has its word, every state its icon or label.

### B. Five palettes, each light and dark

A palette sets the accent and the sidebar ("rail"); the base surfaces above are
shared. Values exactly as the prototype defines them:

| Palette | Rail (light) | Accent | Accent ink | Rail style |
| --- | --- | --- | --- | --- |
| **Navy & emerald** (default) | `#0A2540` | `#00D084` | `#0A2540` | dark rail, logo in a white tile |
| **Light & emerald** | `#FFFFFF` | `#00D084` | `#0A2540` | light rail, bordered, no tile |
| **Midnight & teal** | `#0F172A` | `#14B8A6` | `#042F2E` | dark rail, logo tile |
| **Light & ocean blue** | `#FFFFFF` | `#1069BB` | `#FFFFFF` | light rail, bordered, no tile |
| **Forest & mint** | `#053B2F` | `#34D399` | `#053B2F` | dark rail, logo tile |

Each palette defines the full rail set (`--rail`, `-ink`, `-text`, `-muted`,
`-faint`, `-line`, `-tint`, `-active`, `-accent`, `-border`), the accent set
(`--accent`, `-hover`, `-ink`, `-soft`, `-text`), `--logo-tile`/`--logo-pad` and
`--hatch-2`, plus its dark-theme overrides.

**The accent rule:** a filled accent surface always carries `--accent-ink`
text, and accent-coloured text always uses `--accent-text`. White on `#00D084`
is 2.0:1 and is never used.

### C. Rendering theme and palette with no flash and no script

The root element is rendered server-side with `data-theme="light|dark|system"`
and `data-palette="navy|paper|midnight|ocean|forest"`, taken from the signed-in
account. The generated CSS covers `system` with a
`@media (prefers-color-scheme: dark)` block, so the correct palette and theme
are on first paint with JavaScript off. Pages with no signed-in account (login,
password reset, the setup wizard) use `navy` + `system`.

### D. The palette preference

- **Migration `add_palette_to_users`** — `palette` (nullable string, 20).
  Null means the default (`navy`). Explicit `down()`, verified on PostgreSQL and
  MySQL.
- An allowlist of palette keys in one place (a small `Palette` enum or value
  object); the service rejects anything else, so a tampered form value cannot
  reach the template attribute.
- A **palette picker** beside the existing theme control in the current
  Appearance settings: five swatch cards (rail colour + accent dot + catalogued
  name), CSRF-protected, saved through the existing preference service. It is
  self-service — any member, including a Viewer, sets their own — and it moves
  to the Profile screen when that screen is rebuilt in a later phase.

### E. Typography

- **Plus Jakarta Sans** (variable, 400–800) for all text and **JetBrains Mono**
  (400/600/700) for every figure: amounts, dates in tables, counts, KPIs, chart
  labels. Both vendored via Fontsource (`@fontsource-variable/plus-jakarta-sans`,
  `@fontsource-variable/jetbrains-mono`), SIL OFL licences committed alongside.
  Nothing from `fonts.googleapis.com`.
- A `.num` utility (mono + `tabular-nums`) is the only way a figure gets its
  face, so a later screen cannot forget it.
- A fixed scale taken from the prototype and rounded to a system:

  | Role | Size / weight |
  | --- | --- |
  | KPI value | 22px, 700, mono |
  | Page title | 18px, 700 |
  | Section heading | 16px, 700 |
  | Body / table | 14px (base), 13px dense, 400–500 |
  | Label | 12px, 600 |
  | Caption / eyebrow | 11px, 600, uppercase for eyebrows |

  The prototype's 8–10px text is raised to 11px minimum.

### F. Icons

Lucide, bundled, not fetched: the build takes only the icons referenced (the
prototype uses about 60 — nav, status, actions) from the npm package and emits
one local SVG sprite through the manifest. A Twig function `icon('wallet')`
renders `<svg aria-hidden="true"><use href="…#wallet"/></svg>`, so icons are
server-rendered and work with JavaScript off. An icon beside a label is
decorative; an icon-only button carries a catalogued `aria-label`.

### G. The base component vocabulary

Restyled once, as Twig partials or Tailwind component classes, so later phases
compose rather than invent:

- **Card** — `--surface`, 1px `--border`, 12px radius, `--shadow`.
- **Buttons** — primary (accent fill, accent ink, one per screen), secondary
  (`--ink-btn`), quiet (surface-2), danger (bad fg on bad bg).
- **Form controls** — inputs and selects at 8px radius, visible focus ring in
  `--accent`, error state in bad.
- **Segmented control** and **filter chip** — the prototype's two selection
  patterns.
- **Toggle** — accent track when on, with an accessible checkbox underneath.
- **Badge** — ok / warn / bad / info, pill radius, always with text.
- **Table** — muted header, row hover on `--surface-2`, `.num` right-aligned
  figure columns.
- **Modal** and **toast** — backdrop token, 14px radius.
- **Sidebar item** — `--rail-text`, active = `--rail-active` + 3px accent left
  border, derived from the route server-side.

The existing pages switch to these without changing layout or content: same
routes, same data, new skin.

### H. Charts read the tokens

A small helper reads `--s1`–`--s6`, `--accent`, `--border` and `--muted` from
the computed style and hands them to Chart.js, and re-reads them when the theme
or palette changes. No chart hardcodes a colour. (Chart content is unchanged.)

### I. Contrast fixes to the prototype's values

Measured against WCAG AA (4.5:1 for normal text). These prototype values fail
and are adjusted in `tokens.json` during this phase, keeping the hue:

| Pair | Prototype ratio | Fix |
| --- | --- | --- |
| bad text on bad background (light) | 3.95 | darken fg to ≈ `#B91C1C` |
| Ocean dark: white ink on `#3B8FE0` accent | 3.39 | set dark `--accent-ink` to `#0A2540` |
| Light & emerald / Ocean rail-faint on white rail | 2.56 | darken to ≈ `#64748B` |
| Navy / Forest rail-faint on rail | 4.49 / 4.47 | lighten slightly to clear 4.5 |

Every other checked pair passes (e.g. navy accent-ink on accent 7.7, dark faint
on surface 5.5).

## Explicitly out of scope (leave clean seams, do NOT stub)

- **Any screen rebuild** — dashboard A/B, subscriptions, analytics, budgets,
  calendar, members, profile and settings layouts from the prototype are later
  phases.
- The new concepts the prototype introduces — the Contributor role,
  per-subscription "Only payer" visibility, paused status, category groups, the
  in-app notification inbox, the household switcher. Each is its own decision.
- An instance-wide default palette set by the admin (seam: the null-means-navy
  rule lives in one place).
- User-defined custom palettes or colour pickers.

## Decisions & assumptions (confirm or correct before build)

- This is numbered **Phase 18**, after the last existing phase file.
- **Navy & emerald** is the default for new accounts and signed-out pages.
- Figures use **JetBrains Mono** rather than a proportional face with tabular
  figures; confirm you like the monospace look across tables and KPIs.
- Palette is **per account**, not per household — two members can see
  different palettes.
- The palette picker lives with the **existing** theme control until the Profile
  screen is rebuilt.
- The contrast fixes in section I change a handful of prototype hex values
  slightly; the palettes stay recognisably the same.

## Status

- [x] `assets/theme/tokens.json` with base, semantic, chart and five palette sets
- [x] Build step generating palette × theme × system CSS from the JSON
- [x] Tailwind theme mapped to CSS variables
- [x] Plus Jakarta Sans + JetBrains Mono vendored (Fontsource, OFL files)
- [x] `.num` utility and the type scale
- [x] Lucide sprite built from used icons + `icon()` Twig function
- [x] Root `data-theme` / `data-palette` rendered server-side; signed-out default
- [x] Migration `add_palette_to_users` (+ `down()`)
- [x] Palette allowlist + preference service change + picker (CSRF)
- [x] Base components restyled (card, buttons, controls, segmented, chip,
      toggle, badge, table, modal, toast, sidebar item)
- [x] Chart.js colour helper reading tokens
- [x] Contrast fixes applied; contrast test green
- [x] Phases 7, 8, 9 and 14 amended per "What this supersedes"
- [x] New strings (palette names, picker labels) in `translations/en.php`
- [x] `composer check`, `i18n:check` and the external-host CI guard green on
      PostgreSQL and MySQL

Built as approved, with these decisions:

- **Focus ring:** a `--focus-ring` token — the accent's text shade in light,
  the accent in dark — because the light accents do not reach 3:1 on white.
  On the rail the ring is `--rail-accent`.
- **Chart series:** the light theme's `--s2`, `--s4` and `--s6` were darkened
  to clear 3:1 on the card (`--s2` is the palette's accent-text in light, the
  accent in dark). A slate `--s-other` stays for "everything else".
- **More contrast fixes than section I listed**, found by the test and fixed
  keeping the hue: light `--faint` (4.34 on surface-2), paper/ocean dark
  `--rail-faint` (3.88), and midnight's and ocean-dark's `accent-hover`, which
  darkened under a dark ink and now lighten instead.
- **Additions the prototype lacks:** `--border-control` (field edges at 3:1)
  and `--chart-band` (the trial wash, a literal a canvas can read).
- **Font licences** are copied from `node_modules` at build time, as Inter's
  was, rather than committed. Vite's asset inlining is off, so every font
  subset is a file.
- **Signed-out pages** wear navy; their theme still follows the sign-in
  switch's cookie (system by default).
- **Palette** posts to its own endpoint (`POST /profile/palette`) so a refused
  value writes nothing and cannot discard the rest of the preferences form.
- **No toast exists yet**, so none was restyled; the flash messages use the
  same semantic pairs. The toggle, filter chip and segmented control are in
  the stylesheet for later screens; the sign-in theme switch is the first
  segmented control.
- **Shape and type** stay in `assets/css/tokens.css`; only colours are in the
  JSON, since only colours vary by palette and have contrast to check.

## Definition of done

`docker compose up` runs clean; the migration applies and rolls back; every
existing page renders in all five palettes × light, dark and system with no
layout change and no flash of the wrong theme; nothing loads from a third-party
host; a member can choose a palette and it persists across sessions and devices;
the contrast test passes for every palette; the quality gates and `i18n:check`
are green on both engines. Then update `PHASE.md` to the next phase.

## Tests

- **Contrast:** a PHPUnit test reads `tokens.json` and asserts ≥ 4.5:1 for every
  defined text/background pair in every palette × theme (text on surfaces,
  muted/faint on surfaces, accent-ink on accent, accent-text on surface and
  accent-soft, each semantic fg on its bg, rail text/muted/faint on rail), and
  ≥ 3:1 for large text and focus rings.
- **Preference:** saving a valid palette persists it; an unknown value is
  rejected and nothing is written; null resolves to `navy`.
- **Self-service:** a Viewer can change their own palette; the endpoint requires
  CSRF; one member's palette does not affect another's.
- **Rendering:** the root element carries the account's `data-theme` and
  `data-palette`; a signed-out page carries `navy` + `system`.
- **Offline:** the external-host guard still passes with the new fonts and icons;
  the built manifest contains the font files and the sprite.
- **Icons:** `icon()` resolves a known name through the manifest and fails loudly
  (in dev) on an unknown one.
- **Regression:** the existing functional suites pass unchanged — permissions,
  scoping and money formatting are untouched by the restyle.
