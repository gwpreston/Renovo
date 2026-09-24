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

/** The import, kept, so a page with two charts fetches the chunk once. */
let library = null;

function load() {
    library ??= import('chart.js').then(({ Chart, registerables }) => {
        // Registered on first use rather than at module scope: `registerables`
        // is the whole set of controllers, scales and elements, and registering
        // it is what makes tree-shaking moot — which is fine here, because this
        // module is only ever fetched by a page that wants a chart.
        Chart.register(...registerables);

        return Chart;
    });

    return library;
}

/**
 * Draw a chart on a canvas, replacing anything already drawn on it.
 *
 * @param {HTMLCanvasElement} canvas Where to draw.
 * @param {object} config A Chart.js configuration object.
 * @returns {Promise<object>} The Chart instance, once the library has loaded.
 */
export async function renderChart(canvas, config) {
    const Chart = await load();

    charts.get(canvas)?.destroy();

    // Every label a chart draws is a figure or sits beside one, so the axes,
    // tooltips and legend take the figures face like the tables beside them.
    Chart.defaults.font.family = token('--font-figures', 'ui-monospace, monospace');

    const chart = new Chart(canvas, config);
    charts.set(canvas, chart);

    return chart;
}

/**
 * Draw a chart into a card, and reveal the card's frame around it.
 *
 * **The order matters, and getting it wrong is silent.** A card's frame is
 * hidden until there is a chart in it, so the figures table is what a browser
 * with no script sees. But a canvas inside a `display: none` parent measures
 * zero, and the library writes that measurement onto the canvas as an inline
 * `width: 0; height: 0` it does not revisit — so a chart drawn before its frame
 * was shown is a chart that is never drawn at all. It survived on the dashboard
 * because a resize happened to follow; it is not something to leave to chance.
 *
 * So the frame is revealed first — but only once the library is actually here,
 * because revealing it before that would replace the table with an empty box
 * for as long as the chunk takes to arrive, and for good if it never does. If
 * the chart then fails to construct, the frame goes back and the reader keeps
 * the figures.
 *
 * @param {HTMLCanvasElement} canvas Where to draw.
 * @param {object} config A Chart.js configuration object.
 * @returns {Promise<object>} The Chart instance.
 */
export async function drawInCard(canvas, config) {
    await load();

    const card = canvas.closest('.chart-card');
    card?.classList.add('is-drawn');

    try {
        return await renderChart(canvas, config);
    } catch (error) {
        card?.classList.remove('is-drawn');
        throw error;
    }
}

/**
 * Read a design token, so a chart is drawn in the same colours as everything
 * around it and follows the palette rather than restating it.
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
 * Every colour a chart is allowed to use, read from the tokens as they are
 * right now.
 *
 * This is the one place a chart gets a colour. The six series are
 * categorical — they tell one thing from another and mean nothing — and are
 * written out as literal values by the token build, so a canvas can use them
 * directly. The fallbacks are the default palette's light values and only
 * matter if the stylesheet has not loaded, in which case the page has bigger
 * problems than its chart.
 *
 * Read afresh on every draw rather than cached, because the palette under a
 * drawn chart can change (see `onThemeChange`).
 *
 * @returns {{ series: string[], other: string, accent: string, border: string,
 *             muted: string, text: string, surface: string, band: string }}
 */
export function themeColours() {
    return {
        series: [
            token('--s1', '#0A2540'),
            token('--s2', '#047857'),
            token('--s3', '#3B82F6'),
            token('--s4', '#D97706'),
            token('--s5', '#8B5CF6'),
            token('--s6', '#475569'),
        ],
        other: token('--s-other', '#8792A2'),
        accent: token('--accent', '#00D084'),
        border: token('--border', '#E2E8F0'),
        muted: token('--muted', '#4A5568'),
        text: token('--text', '#0A2540'),
        surface: token('--surface', '#FFFFFF'),
        band: token('--chart-band', 'rgba(180, 83, 9, 0.14)'),
    };
}

/**
 * Call `redraw` whenever the tokens under a drawn chart may have moved.
 *
 * Two things move them: the root's `data-theme` or `data-palette` changing,
 * and — for an account set to "system" — the machine flipping between light
 * and dark. An account that has chosen a theme explicitly is unaffected by the
 * second; the callback runs anyway, and redraws in the same colours.
 *
 * @param {() => void} redraw
 */
export function onThemeChange(redraw) {
    new MutationObserver(redraw).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme', 'data-palette'],
    });

    if (typeof window.matchMedia === 'function') {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', redraw);
    }
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
