# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 14 — polish, responsiveness, accessibility and sign-off

The screens exist; this phase makes them finished. It is deliberately last and
deliberately separate, because the temptation across Phases 9–13 is to polish each
screen as it lands and never see the interface whole. Doing it once, at the end,
against the complete set, is what catches the seams between them.

## Interaction states

The states the design specifies, applied consistently across every screen built
in this sequence: nav items take the amber accent when active; table rows lift to
the hover surface (`#202028`); the one primary action per screen carries the amber
gradient and nothing else competes with it. A second amber button on a screen is a
bug this phase exists to find — the accent means "the action here", and two of them
means neither does.

## Responsiveness, end to end

Every screen is walked from wide to narrow: the grid collapses to one column, the
rail becomes the bottom bar or drawer, tables scroll or restack rather than
overflow, and the charts hold up at small widths. The calendar's phone layout is
in scope here too — it has needed weekday attributes the template must actually
emit for the narrow grid to work, so it is checked rather than assumed.

## Accessibility

- **Density stays invisible to assistive tech.** Compact and comfortable are the
  same markup with different padding; this phase confirms a screen reader still
  sees no difference, because that property is easy to break during a restyle.
- **Contrast.** A dark theme with a muted-grey secondary text is where contrast
  quietly fails; secondary text and every state are checked against the surface
  they sit on.
- **The accent is never the only signal.** A status badge or an active item is not
  distinguished by amber alone, so colour-blind and greyscale readers get the same
  information.
- **Keyboard.** Everything reachable and operable by keyboard, the existing
  shortcuts intact, focus visible against the dark surface.

## Theme and language, verified

Every screen is viewed in **system, light and dark**, since "dark first" never
meant "dark only". Every string is confirmed to come from the catalogue —
`i18n:check` clean, and a spot-check in a non-English locale to confirm dates and
money format through ICU (`4 sept. 2026`, `12,99 £`) rather than a hardcoded
format leaking through. A number or a currency symbol written directly into a
template is the failure this pass is looking for.

## The gates

The same three that gate every change, plus the i18n check, plus the suites that
prove the guarantees the restyle must not have touched:

```
composer check        # phpunit, phpcs (PSR-12), phpstan level 6
php bin/console i18n:check
```

The integration and functional suites matter especially here: scoping, isolation
and permissions are enforced in SQL, and a re-skin that accidentally exposed a
control or a figure across households would be caught by them, not by looking at
the screen. A restyle should change what the interface *looks like* and nothing
about what it is *allowed to show* — these tests are how that claim is kept
honest.

## Done when

Every screen is consistent in its states, responsive to narrow widths,
accessible, correct in all three themes and fully catalogued; the full suite
passes against both PostgreSQL and MySQL as CI runs it; and the interface, seen
whole, reads as one design rather than seven screens that were built one at a
time.