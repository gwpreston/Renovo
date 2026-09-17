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
 *
 * Usage from a page's own script:
 *
 *     await window.Renovo.chart(canvas, { type: 'line', data: … });
 *     element.append(window.Renovo.icon('calendar'));
 */

import { renderChart } from './charts.js';
import { drawSpendChart, watchTheme } from './dashboard.js';
import { hydrateIcons, icon, iconNames } from './icons.js';

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

    /* A page with no spend chart on it returns immediately, so this costs a
       querySelector; Chart.js itself is only fetched when there is one. */
    drawSpendChart();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
} else {
    hydrate();
}

document.addEventListener('htmx:afterSwap', (event) => {
    hydrateIcons(event.target instanceof Element ? event.target : document);
});

watchTheme();
