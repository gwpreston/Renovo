/**
 * The bundled entry point.
 *
 * This is deliberately small. The application's own behaviour — keyboard
 * shortcuts, the quick-add dialog, passkey registration — lives in
 * `public/assets/app.js` and `public/assets/webauthn.js`, which are
 * hand-written, served directly and untouched by this build. htmx is vendored
 * the same way. None of them needed bundling and so none of them were moved.
 *
 * What this file provides is the two things that do: a chart library too large
 * to load on every page, and an icon set that has to be tree-shaken to be worth
 * having. It exposes both on `window.Renovo`, and — since Phase 9 gave the
 * shell a navigation made of icons — fills in the icon slots the server marked
 * up. Phase 10 added the first page that draws a chart: the dashboard's
 * twelve-month spend, from a payload the server rendered beside the canvas.
 * Phase 12 added the second kind and the first page carrying two — the
 * analytics screen's spending trajectory beside its category donut — which is
 * why the drawing below is a list rather than a call.
 *
 * Usage from a page's own script:
 *
 *     await window.Renovo.chart(canvas, { type: 'line', data: … });
 *     element.append(window.Renovo.icon('calendar'));
 */

import { renderChart } from './charts.js';
import { drawCategoryDonuts } from './category-donut.js';
import { hydrateIcons, icon, iconNames } from './icons.js';
import { drawSpendCharts } from './spend-chart.js';

/*
 * Assigned, not merged: this is the only thing that writes window.Renovo, and
 * a second definition appearing later should be a visible conflict rather than
 * a silent half-overwrite.
 */
window.Renovo = {
    chart: renderChart,
    icon,
    iconNames,
    hydrateIcons,
};

/**
 * Every kind of chart this application draws.
 *
 * Each returns immediately on a page that has none of its kind, so a page with
 * no chart pays for two `querySelectorAll` calls and nothing else; Chart.js is
 * fetched only when there is something to draw with it.
 */
function drawCharts(root = document) {
    return Promise.all([drawSpendCharts(root), drawCategoryDonuts(root)]);
}

/*
 * The shell's icons, drawn once the document is there to draw them into.
 *
 * This is a module, so it is deferred and the markup already exists by the
 * time it runs; the readyState test is for the case where it does not, which
 * is a module fetched from cache faster than the parser. Running again after
 * an htmx swap covers a fragment that arrived with icon slots of its own —
 * `hydrateIcons` only fills empty ones, so doing it twice costs nothing.
 */
function hydrate() {
    hydrateIcons(document);
    drawCharts();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
} else {
    hydrate();
}

document.addEventListener('htmx:afterSwap', (event) => {
    hydrateIcons(event.target instanceof Element ? event.target : document);
});

/*
 * Redraw when the system flips between light and dark.
 *
 * A chart's colours are read from the design tokens at the moment it is drawn,
 * so one drawn in light mode keeps light-mode ink until something asks for it
 * again. An account that has chosen a theme explicitly is unaffected: its
 * tokens do not move.
 */
if (typeof window.matchMedia === 'function') {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        drawCharts();
    });
}
