/**
 * Chart rendering, loaded on demand.
 *
 * Chart.js is around a fifth of a megabyte, and most pages in this application
 * show a table. So it is behind a dynamic `import()`: Vite splits it into a
 * chunk of its own, and a page that never calls `renderChart` never downloads
 * it. The chunk is served from this instance like every other built file —
 * the lazy part is when the browser asks for it, not where from.
 */

/** One Chart per canvas, so a re-render replaces rather than stacks. */
const charts = new WeakMap();

/**
 * Draw a chart on a canvas, replacing anything already drawn on it.
 *
 * @param {HTMLCanvasElement} canvas Where to draw.
 * @param {object} config A Chart.js configuration object.
 * @returns {Promise<object>} The Chart instance, once the library has loaded.
 */
export async function renderChart(canvas, config) {
    const { Chart, registerables } = await import('chart.js');

    // Registered on first use rather than at module scope: `registerables` is
    // the whole set of controllers, scales and elements, and registering it is
    // what makes tree-shaking moot — which is fine here, because this module
    // is only ever fetched by a page that wants a chart.
    Chart.register(...registerables);

    charts.get(canvas)?.destroy();

    const chart = new Chart(canvas, config);
    charts.set(canvas, chart);

    return chart;
}

/**
 * Read a design token, so a chart is drawn in the same colours as everything
 * around it and follows the theme rather than restating it.
 *
 * @param {string} name The custom property, including its leading dashes.
 * @param {string} fallback What to use when the stylesheet has not loaded.
 * @returns {string} The resolved colour.
 */
export function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value === '' ? fallback : value;
}

/**
 * The payload a canvas names, parsed.
 *
 * The server writes it into a non-executing <script> element beside the canvas
 * and the canvas points at it by id, which is what lets one page carry two
 * charts without either of them guessing which data is theirs.
 *
 * @param {HTMLCanvasElement} canvas The canvas whose payload to read.
 * @returns {object|null} The payload, or null if it is missing or malformed.
 */
export function payloadFor(canvas) {
    const element = document.getElementById(canvas.dataset.chartData);
    if (element === null) {
        return null;
    }

    try {
        return JSON.parse(element.textContent);
    } catch (error) {
        return null;
    }
}
