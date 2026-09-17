/**
 * The dashboard's spend chart.
 *
 * The server has already done everything that involves money: the twelve
 * months arrive as integer minor units for the bars to be drawn from, and
 * every string on the chart — each month's total in the tooltip, each label on
 * the axis — was formatted by ICU in the same place the rest of the page's
 * figures were. Nothing here divides a currency by a hundred, so the chart
 * cannot disagree with the table beneath it about what a month cost.
 *
 * The axis ticks are pinned to the values the server named. Left to itself the
 * library would invent tick values and need them formatted, which is the one
 * thing this file is arranged to avoid.
 */

import { renderChart } from './charts.js';

/** Where the payload and the canvas describe each other. */
const CANVAS = 'canvas[data-chart="spend"]';

/**
 * Read a design token, so the chart is drawn in the same colours as
 * everything around it and follows the theme rather than restating it.
 */
function token(name, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();

    return value === '' ? fallback : value;
}

function payloadFor(canvas) {
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

function configFor(data) {
    const values = data.months.map((month) => month.minor);
    const displays = data.months.map((month) => month.display);
    const tickLabels = new Map(data.ticks.map((tick) => [tick.value, tick.label]));

    const bar = token('--accent', '#097a70');
    /* Amber is this application's "money is about to move"; the busiest month
       is the one worth looking at, so it is the one that gets it. */
    const peak = token('--warning', '#b4430f');
    const grid = token('--border', 'rgba(16, 24, 40, 0.12)');
    const text = token('--text-muted', '#5b6472');

    return {
        type: 'bar',
        data: {
            labels: data.months.map((month) => month.label),
            datasets: [{
                label: window.renovoI18n.t('dashboard.spend'),
                data: values,
                backgroundColor: values.map((_, index) => (index === data.peak_index ? peak : bar)),
                borderRadius: 6,
                borderSkipped: false,
                maxBarThickness: 48,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            /* The canvas carries an aria-label and the table beside it carries
               the figures, so the library's own accessibility tree adds
               nothing a screen reader has not already been given. */
            animation: { duration: 200 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        /* The formatted string the server produced, not a
                           number this file would have to format. */
                        label: (context) => displays[context.dataIndex],
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: grid },
                    ticks: { color: text },
                },
                y: {
                    beginAtZero: true,
                    max: data.axis_max > 0 ? data.axis_max : undefined,
                    grid: { color: grid },
                    border: { display: false },
                    afterBuildTicks: (scale) => {
                        scale.ticks = data.ticks.map((tick) => ({ value: tick.value }));
                    },
                    ticks: {
                        color: text,
                        callback: (value) => tickLabels.get(value) ?? '',
                    },
                },
            },
        },
    };
}

/**
 * Draw the chart, if this page has one.
 *
 * The card is marked as drawn only once the library has loaded and the chart
 * exists. Until then — and for good on a browser that never runs this — the
 * card shows the table of figures the server rendered, which is the same data
 * the picture would have been.
 */
export async function drawSpendChart(root = document) {
    const canvas = root.querySelector(CANVAS);
    if (canvas === null) {
        return;
    }

    const data = payloadFor(canvas);
    if (data === null || !Array.isArray(data.months) || data.months.length === 0) {
        return;
    }

    await renderChart(canvas, configFor(data));
    canvas.closest('.chart-card')?.classList.add('is-drawn');
}

/**
 * Redraw when the system flips between light and dark.
 *
 * The colours above are read from the tokens at the moment of drawing, so a
 * chart drawn in light mode keeps light-mode ink until something asks for it
 * again. An account that has chosen a theme explicitly is unaffected: its
 * tokens do not move.
 */
export function watchTheme() {
    if (typeof window.matchMedia !== 'function') {
        return;
    }

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        drawSpendChart();
    });
}
