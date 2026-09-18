/**
 * The twelve-month spend chart.
 *
 * Drawn on the dashboard and, as the analytics screen's spending trajectory,
 * on `/stats`. One payload builder on the server and one drawing routine here,
 * so the two pictures cannot disagree about a month.
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

import { drawInCard, payloadFor, token } from './charts.js';

/** Where the payload and the canvas describe each other. */
const CANVAS = 'canvas[data-chart="spend"]';

function configFor(data) {
    const values = data.months.map((month) => month.minor);
    const displays = data.months.map((month) => month.display);
    const tickLabels = new Map(data.ticks.map((tick) => [tick.value, tick.label]));

    const bar = token('--accent', '#086f66');
    /* Amber is this application's "money is about to move"; the busiest month
       is the one worth looking at, so it is the one that gets it. */
    const peak = token('--warning', '#a83f0e');
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
 * Draw every spend chart on the page.
 *
 * `querySelectorAll`, not `querySelector`: the dashboard has one of these and
 * the analytics screen has one beside a donut, and a page is free to have two.
 * Each canvas names its own payload, so they do not have to be told apart.
 *
 * A card's frame is revealed only once the library is here, and put back if the
 * chart does not construct. Until then — and for good on a browser that never
 * runs this — the card shows the table of figures the server rendered, which is
 * the same data the picture would have been.
 */
export async function drawSpendCharts(root = document) {
    const canvases = Array.from(root.querySelectorAll(CANVAS));

    await Promise.all(canvases.map(async (canvas) => {
        const data = payloadFor(canvas);
        if (data === null || !Array.isArray(data.months) || data.months.length === 0) {
            return;
        }

        await drawInCard(canvas, configFor(data));
    }));
}
