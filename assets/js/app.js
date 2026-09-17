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
 * having. It exposes them on `window.Renovo` and does nothing else — no page
 * draws a chart yet, and the view work that will is Phase 8's.
 *
 * Usage from a page's own script:
 *
 *     await window.Renovo.chart(canvas, { type: 'line', data: … });
 *     element.append(window.Renovo.icon('calendar'));
 */

import { renderChart } from './charts.js';
import { icon, iconNames } from './icons.js';

/*
 * Assigned, not merged: this is the only thing that writes window.Renovo, and
 * a second definition appearing later should be a visible conflict rather than
 * a silent half-overwrite.
 */
window.Renovo = {
    chart: renderChart,
    icon,
    iconNames,
};
