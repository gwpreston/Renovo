# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 8 — the design system

The first six phases built a complete application and a plain interface over it.
This phase and the five after it re-skin that interface to the Renovo dashboard
design — a dark, fintech-styled surface — **without moving any logic out of the
services**. Every figure these screens show is already computed server-side; the
work is presentation, not new capability. One exception is called out where it
arises (a spend-insight signal in Phase 13); everything else is a new way to
render numbers the application already knows.

This phase depends on **Phase 7**, which put the build pipeline and the
self-hosted assets in place. It names the design language — colour, type,
spacing, the component vocabulary — as Tailwind theme tokens the pipeline
compiles. Phase 7 is *how* assets are built and served; this phase is *what* they
express.

Nothing here changes the four rules in `SPEC.md`. In particular: money reaches a
template as an integer number of minor units and a currency, formatted by ICU at
the very end; a combined total is shown only when every currency in it can be
converted, and withheld with the missing currency named when one cannot; and
every string a screen shows comes from a catalogue. A redesign that hardcoded a
number, a currency symbol or an English label would break a guarantee the
application makes, not merely look wrong.

## The delivery model — decided

**The interface stays server-rendered: new Twig templates and a compiled
stylesheet extend the existing Twig/htmx interface. A separate single-page client
against `/api/v1` was considered and rejected.**

The reason is that the server already solves, once, the things a single-page
client would have to re-solve in JavaScript: it formats money per locale, applies
the SHARED/ISOLATED owner override, enforces permissions on every route, issues
CSRF tokens, and renders the same figures the API returns. A single-page client
would rebuild all of that — locale-aware money formatting, the visibility rules,
the three-state `reminder_days`, auth without an ambient cookie — for a
subscription tracker one household reads a few times a week. The design is a skin,
and a skin does not need a second application underneath it.

So every screen in Phases 9–14 is a Twig template extending the shell, styled by
the token layer below, with htmx for the interactivity that needs it. The
`/api/v1` surface is untouched and remains what it already is — for external
clients, not for this interface.

## The colour system — reconciled with the brand

The design mock specified an amber accent. The actual logo is a **teal-green →
blue gradient** mark with a navy wordmark. An amber accent beside a green-and-blue
logo reads as two brands in one window, so the palette is reconciled to the logo
rather than the mock, and the amber is not discarded but **given a job it is
actually suited to**:

- **Brand / primary / active — the logo gradient.** Teal-green (`#1fae8f`-ish,
  sampled from the mark) into blue (`#2b74d6`-ish). Used for the one primary
  action per screen, the active nav item, the featured-card highlight and the
  brand mark. This is the colour the eye should follow.
- **Urgency / attention — amber→orange (`#ff5722`→`#ff784e`).** Reserved for the
  states that genuinely warrant a warm warning: renewing soon, a cancel-by
  deadline, a budget projected over. A warm colour for "money is about to move"
  is the correct semantic, and it keeps the mock's amber working for something
  real instead of competing with the brand for the same meaning.
- **Error / overspend — a muted red**, distinct from amber, for a genuine problem
  rather than a heads-up.

Because brand and urgency are now different hues, a screen can show "this is the
action" and "this needs attention" at the same time without them fighting — which
the single-accent mock could not.

| Token group | Value |
| --- | --- |
| Surfaces | near-black page (`#0b0b0e`/`#121215`), slate cards (`#1a1a20`), hover (`#202028`) |
| Brand / primary / active | logo gradient, teal-green → blue |
| Urgency / attention | amber → orange (`#ff5722`→`#ff784e`) |
| Error / overspend | muted red |
| Text | white primary, muted grey (`#9e9eab`) secondary |
| Surface treatment | 16px radius, 1px inner border at `rgba(255,255,255,0.08)`, gradient highlight on featured cards only |

Exact gradient stops are sampled from the supplied logo during this phase and
fixed as tokens, so every gradient in the app is the same two colours as the mark
rather than an approximation.

## Typography — Inter, and why

The typeface is **Inter**, self-hosted as a variable font by Phase 7. It is a
deliberate choice rather than the mock's default, and the deciding reason is
narrow and specific to this application: **Inter has true tabular figures**
(`font-feature-settings: "tnum"`), so columns of currency line up digit under
digit. In a money app that reads down tables of amounts all day, proportional
figures that shift column width row to row are a real legibility cost, and this is
the family that removes it. Tabular numerals are switched on for every figure —
tables, KPIs, charts — and left off for running prose.

The wordmark in the logo is its own rounded geometric form and is not recreated
in a webfont; the logo carries the brand's character, and the interface type
stays quiet underneath it so the data is what reads. A single family keeps the
self-hosted bundle small, which matters more here because nothing loads from a
CDN.

A small, fixed scale rather than ad-hoc sizes:

| Role | Size / weight |
| --- | --- |
| KPI value | 2.5rem+, 700, tabular |
| Section heading | 1.25rem, 600 |
| Body / table | 0.9375rem, 400–500, tabular for figures |
| Label / caption | 0.8125rem, 500, muted grey |

## The component library — decided

Made once here so no later phase re-litigates it, and all three are bundled by
Phase 7 with nothing fetched at runtime:

- **Tailwind CSS**, not Bootstrap. The design is a bespoke dark surface with its
  own tokens; Tailwind is utility-first and maps straight onto them, and its
  build purges everything unused so the shipped stylesheet is small. Bootstrap
  would impose a component look this design would spend effort overriding. The
  design tokens above are Tailwind theme values, so a utility and a hand-written
  rule cannot disagree about what "brand" or "card" means.
- **Chart.js** for the dashboard and analytics charts (Phases 10 and 12). One
  charting library, bundled, fed integers-converted-at-the-boundary — never a
  float currency value.
- **Lucide** icons, not Font Awesome. Lighter, MIT-licensed, tree-shaken to the
  handful actually used, and its thin consistent stroke suits the dark minimal
  surface. Only the icons a screen references are bundled.

## Dark is a theme, not the application

The design is "dark first", but Renovo already gives every account **system,
light or dark** under Settings → Appearance, and that is not thrown away to match
a mock. The tokens are defined for dark and for light; "dark first" means dark is
the better-tuned of the two and the one the screenshots show, not that light
stops existing. An account that has chosen light, or a browser that asks for it,
gets the light token set. The brand gradient, the semantic hues and the layout
are shared; only the surface and text tokens flip.

## How the built stylesheet is served

The compiled CSS and JS come out of the Phase 7 pipeline with content-hashed
filenames and a manifest; templates resolve a logical name to the hashed file
through that manifest, so a deploy can never leave a returning browser on last
week's stylesheet — the filename itself changed. This supersedes, for built
assets, the modification-time versioning the `asset()` helper does; `asset()`
still covers any static file that does not go through the build.

## Reconciling the design against real data

The design borrows neo-bank furniture that this application has no data for, and
this phase writes down the disposition of each so later phases do not stall
mid-screen inventing numbers. Renovo tracks **what is due, not a ledger of what
has been paid**, so anything that presumes a balance or a ledger is dropped or
re-pointed rather than mocked:

| In the design | Disposition |
| --- | --- |
| Total Revenue / Inflow | Dropped. There is no inflow in a subscription tracker. |
| Total Savings | Dropped, unless re-pointed at a concrete figure (e.g. spend removed by cancellations) in a later phase. |
| Virtual Card Preview (masked PAN, VISA) | Dropped. No card, no PAN. |
| Manage Balance quick action | Dropped. No balance. |
| Upgrade Plan sidebar card | Dropped. Self-hosted; no plans. |
| "Subscription Usage — $1200 used from $299 limit" | Re-pointed at budget vs projected spend (Phase 10), which is the real version of this. |
| AI Insight card | Re-pointed at a rule-based spend-insight signal (Phase 13), which needs no model and invents nothing. |

The rule for the whole re-skin: a card either binds to a real figure or it does
not ship. A dashboard with an invented number on it is worse than one card
lighter.

## Done when

The tokens exist as Tailwind theme values and are consumed by the existing pages
without regression; the brand gradient and the semantic hues are fixed from the
logo; Inter renders with tabular figures on; both theme sets render; the built
stylesheet resolves through the manifest; `phpcs`, `phpstan` and `phpunit` still
pass; `i18n:check` is clean. No screen from Phases 9–14 is built yet — this phase
and Phase 7 are the foundation they stand on.

---

## Built — what actually landed

Every item above is done. Notes on the decisions that were open when the phase
started:

- **The logo was not in the repository**, so the gradient stops are PHASE.md's
  own stated values, `#1fae8f` → `#2b74d6`. They are written down once, as
  `--brand-from` and `--brand-to` in `assets/css/tokens.css`; re-sampling the
  real mark is a two-line edit and nothing else has to move.
- **The hand-written `public/assets/app.css` moved into the build** and was
  deleted. The token guarantee — that a utility and a rule cannot disagree
  about what "brand" or "card" means — needs one compilation to own both.
  Preflight came on with it, and `assets/css/base.css` re-establishes each
  browser default the templates actually rely on. The cost is that changing
  how the application looks now needs `npm run build`, which is recorded in
  the README.
- **The logo gradient cannot carry white text**, so there are two: the mark's
  own stops for surfaces nothing sits on, and `--gradient-action` — the same
  hues darkened — for a filled button. White on the real mark is 2.8:1 at the
  teal stop.
- **Two Preflight regressions were found by looking at the running app** and
  fixed: `<dialog>` lost the `margin: auto` that centres a modal, and a
  checkbox filter stacked above its own label.
- **Contrast is a test, not a judgement.** `DesignTokensTest` asserts every
  text token clears AA on the surface it is used against, in both themes.
