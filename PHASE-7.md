# PHASE.md — current build state

Single source of truth for what to build **right now**. SPEC.md = full plan ·
build-guide = file map · CLAUDE.md = standing rules. When you start this phase,
copy this file to `PHASE.md` at the repo root.

# Phase 7 — the build pipeline and offline assets

The re-skin needs Tailwind compiled, a little JavaScript bundled (Chart.js and a
handful of Lucide icons), and a webfont served — and it needs all of it to run
**with no connection to any CDN**, because an instance is expected to work
offline and on a private network. This phase builds that toolchain once, so every
phase after it has somewhere to put a stylesheet, a script or an icon and a
guarantee about where it is served from. It adds no feature and touches no
service; it is pure infrastructure, and it comes first because Phase 8's design
tokens have nowhere to compile to until it exists.

## The offline rule, stated plainly

**Nothing the browser loads comes from a third-party host.** No `<link>` to a
font CDN, no `<script>` to a chart CDN, no stylesheet from a package's own CDN.
Every byte the page pulls is served from this instance's own web root. This is
not a preference to be relaxed under deadline — a self-hosted instance on a LAN or
behind Tailscale may have no route to a CDN at all, and a page that silently
depends on one is a page that breaks in exactly the deployment this application is
built for.

The distinction that makes it workable: **build time may use the network, run
time may not.** Installing dependencies from the npm registry to *produce* the
bundle is fine, the same way Composer pulls PHP packages. What is forbidden is the
*running* application reaching out — so the font, the chart library and the icons
are compiled into local files at build time and served locally forever after.

## The tool — Vite (with a webpack note)

The pipeline is **Vite**. It compiles Tailwind through PostCSS, bundles the JS,
emits content-hashed filenames with a manifest, and has a watch mode for
development — all with far less configuration than an equivalent webpack setup, on
a project that is a PHP/Twig app rather than a JavaScript application and so needs
a bundler only for its assets, not for its pages.

If webpack is preferred — a house standard, familiarity — **Webpack Encore** is
the PHP-native equivalent and reaches the same end: compiled Tailwind, a bundled
JS entry, hashed filenames and a `manifest.json`. This document is written around
the *outcome* (hashed local files plus a manifest a Twig helper reads), which both
tools produce; only the config file differs. The outcome is what Phase 8 depends
on, not the tool.

## The font is vendored, not linked

Inter is brought in through **Fontsource** (`@fontsource-variable/inter`) — Google
Fonts' families repackaged for self-hosting and installed from npm, not fetched
from `fonts.googleapis.com`. The build copies the `.woff2` files into the output
and emits the `@font-face` rules pointing at them, so the font is served from this
instance like any other asset. Its licence (the SIL Open Font License) is
vendored alongside it. The result is that "use a Google font" and "load nothing
from Google" are both satisfied: the font is Google's, the serving is entirely
local.

## What it outputs, and how Twig finds it

The build writes hashed files into the web root's asset directory and a manifest
mapping each logical name to its hashed file. A small Twig function looks a
logical name up in that manifest and prints the hashed URL, so a template asks for
`app.css` and gets `app.4f2a1c.css`. Because the filename changes with the
contents, a returning browser after a deploy fetches the new file rather than
serving a stale one from cache — the problem the earlier `asset()` modification-
time helper solved, solved here by the manifest for everything that goes through
the build. `asset()` stays for static files that do not.

Only the web root is reachable over HTTP, so the source (`node_modules`, the Vite
config, the entry files) sits outside it and only the built output is served —
consistent with the rule that nothing outside `public/` is reachable.

## Fitting the existing workflow

- **Development.** A watch mode rebuilds on change; `bin/dev-setup.sh` gains a step
  that installs the JS dependencies and runs an initial build, so the one-command
  start still serves a styled page. A container for the asset watcher joins the
  compose stack for development, mirroring how the app is already run.
- **Production.** The build runs during image build, so the shipped image already
  contains the hashed assets and the manifest; the running container fetches
  nothing. The app and web containers must serve the *same* built output — the
  same lesson the stale-asset fix recorded, applied to a real bundler now.
- **CI.** The build runs in CI and fails the job if it fails. A grep-style guard
  fails the build if any template or stylesheet references an external `http(s)`
  asset host, so the offline rule is enforced by the pipeline rather than trusted
  to review.

## Deferred deliberately

No CSS-in-JS, no component framework runtime, no service worker. Those belong to a
single-page client, which Phase 8 records was considered and rejected. The
pipeline compiles assets for a server-rendered app and does no more.

## Done when

`npm install` followed by the build produces hashed CSS and JS and the vendored
font in the web root, with a manifest the Twig helper resolves; the running
application loads nothing from any third-party host, confirmed by the CI guard;
the dev watch mode and `bin/dev-setup.sh` produce a styled page from a clean
checkout; the production image contains its assets; and the existing quality gates
still pass. Phase 8 can now define what these compiled assets express.