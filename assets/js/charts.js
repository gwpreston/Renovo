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
