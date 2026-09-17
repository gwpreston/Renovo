/**
 * The analytics screen's category donut.
 *
 * The same contract as the spend chart, for the same reason: the server has
 * already done everything that involves money. Each segment arrives as integer
 * minor units with its share and its formatted amount alongside, so nothing
 * here divides a currency by a hundred and the tooltip cannot print a figure
 * the table beneath it disagrees with.
 *
 * The centre label is not drawn by the library either. It is HTML positioned
 * over the canvas by the stylesheet, so the total this donut represents is the
 * server's ICU string like every other figure on the page.
 *
 * **There is no donut without a single whole.** When the categories span
 * currencies with no rate between them the server sends no payload at all and
 * renders the per-currency figures instead, so this file never has to decide
 * what a segment of an incomplete total would mean.
 */

import { drawInCard, payloadFor, token } from './charts.js';

/** Where the payload and the canvas describe each other. */
const CANVAS = 'canvas[data-chart="donut"]';

/**
 * The categorical palette, in order.
 *
 * Six named series and one for the tail, defined per theme in `tokens.css`, so
 * a segment follows light and dark like everything else. The fallbacks are the
 * light palette's values: they matter only if the stylesheet has not arrived,
 * in which case the chart has bigger problems than its hues.
 */
const SERIES = [
    ['--series-1', '#097a70'],
    ['--series-2', '#1069bb'],
    ['--series-3', '#7a4fbd'],
    ['--series-4', '#b4430f'],
    ['--series-5', '#8a6d1f'],
    ['--series-6', '#0d6b8f'],
];

const TAIL = ['--series-other', '#6b7280'];

function configFor(data, otherLabel) {
    const labels = data.slices.map((slice) => (slice.is_other ? otherLabel : slice.name));
    const values = data.slices.map((slice) => slice.minor);

    /* The tail always takes the muted hue, whichever position it lands in, so
       "everything else" reads as everything else rather than as a seventh
       category. */
    const colours = data.slices.map((slice, index) => {
        const [name, fallback] = slice.is_other ? TAIL : SERIES[index % SERIES.length];

        return token(name, fallback);
    });

    const text = token('--text-muted', '#5b6472');
    /* The ring is cut out of the card, not painted on it, so the gaps between
       segments have to be the card's own surface. */
    const surface = token('--surface', '#ffffff');

    return {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colours,
                borderColor: surface,
                borderWidth: 2,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            /* Wide enough for the centre label the stylesheet places there. */
            cutout: '68%',
            animation: { duration: 200 },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: text,
                        boxWidth: 12,
                        boxHeight: 12,
                        usePointStyle: true,
                        pointStyle: 'circle',
                    },
                },
                tooltip: {
                    callbacks: {
                        /* The formatted string the server produced, and the
                           share it already worked out — not a number this file
                           would have to format or a ratio it would have to
                           divide. */
                        label: (context) => {
                            const slice = data.slices[context.dataIndex];

                            return `${slice.display} (${slice.percent}%)`;
                        },
                    },
                },
            },
        },
    };
}

/**
 * Draw every category donut on the page.
 *
 * Revealing the frame is what puts the centre label over the ring and hides the
 * figures table from sight, and `drawInCard` is what keeps that in the right
 * order. Until the library arrives — and for good on a browser that never runs
 * this — the card shows the table, which is the same data the picture would
 * have been.
 */
export async function drawCategoryDonuts(root = document) {
    const canvases = Array.from(root.querySelectorAll(CANVAS));

    await Promise.all(canvases.map(async (canvas) => {
        const data = payloadFor(canvas);
        if (data === null || !Array.isArray(data.slices) || data.slices.length === 0) {
            return;
        }

        /* The tail's name is the one label that needed translating, so the
           server resolved it and put it on the canvas rather than sending a
           second copy of the catalogue. */
        await drawInCard(canvas, configFor(data, canvas.dataset.otherLabel ?? ''));
    }));
}
