/**
 * The twelve-month spend chart.
 *
 * Drawn four times across two screens: the twelve months ahead and the twelve
 * behind, on the dashboard and again on `/stats`. One payload builder on the
 * server and one drawing routine here, so no two of them can disagree about a
 * month, and a reader comparing the year behind with the year ahead is
 * comparing pictures drawn to the same rules.
 *
 * Which way a chart looks is not something this file decides or needs to ask:
 * the payload says which bucket is a part month and whether there is a trial
 * line to draw, and everything below follows from those two.
 *
 * The server has already done everything that involves money: the twelve
 * months arrive as integer minor units for the lines to be drawn from, and
 * every string on the chart — each month's total in the tooltip, each label on
 * the axis — was formatted by ICU in the same place the rest of the page's
 * figures were. Nothing here divides a currency by a hundred, so the chart
 * cannot disagree with the table beneath it about what a month cost.
 *
 * The axis ticks are pinned to the values the server named. Left to itself the
 * library would invent tick values and need them formatted, which is the one
 * thing this file is arranged to avoid.
 *
 * **Two lines, and the band between them is the subject.** The upper line is
 * every charge the horizon holds; the lower is the same months with the trials
 * taken out. The shaded gap is therefore what converting trials will add to a
 * month that is not paying for them yet — the one future cost a user can still
 * do something about. When no trial converts in the horizon the two lines would
 * coincide, so the server says `has_trials: false` and only one is drawn.
 *
 * Both lines are read from `--accent` and `--warning`, so the chart follows the
 * theme rather than restating it, and the two are told apart by dash as well as
 * by hue for a reader who cannot rely on the second.
 */

import { drawInCard, payloadFor, token } from './charts.js';

/** Where the payload and the canvas describe each other. */
const CANVAS = 'canvas[data-chart="spend"]';

/** Enough of a marker to be a hit target, drawn only where it means something. */
const PEAK_RADIUS = 5;

/**
 * A vertical rule under the hovered month.
 *
 * A line chart is read by finding a month and looking up, and a tooltip alone
 * leaves the eye to do that unaided across twelve columns. Drawn beneath the
 * datasets so it never crosses a line it is meant to help read.
 */
function crosshairFor(colour) {
    return {
        id: 'spendCrosshair',
        beforeDatasetsDraw(chart) {
            const active = chart.tooltip?.getActiveElements?.() ?? [];
            if (active.length === 0) {
                return;
            }

            const { ctx, chartArea } = chart;
            const x = active[0].element.x;

            ctx.save();
            ctx.beginPath();
            ctx.moveTo(x, chartArea.top);
            ctx.lineTo(x, chartArea.bottom);
            ctx.lineWidth = 1;
            ctx.strokeStyle = colour;
            ctx.stroke();
            ctx.restore();
        },
    };
}

/**
 * The busiest month's figure, written beside its point.
 *
 * One label rather than twelve: a number on every point is a table pretending
 * to be a picture. It wears the page's ink rather than the line's colour, so
 * the colour stays a statement about which series a mark belongs to.
 *
 * A factory, not a shared object, for the same reason the crosshair is: an
 * inline plugin is handed to one chart, and closing over that chart's values
 * is what stops a page with two charts labelling both from one of them.
 */
function peakLabelFor(index, displays, colour) {
    return {
        id: 'spendPeakLabel',
        afterDatasetsDraw(chart) {
            if (index === null || index === undefined) {
                return;
            }

            const point = chart.getDatasetMeta(0)?.data?.[index];
            if (point === undefined) {
                return;
            }

            const { ctx, chartArea } = chart;
            /* Flipped below the point near the top of the plot, where there is
               no room above it for a label that would otherwise be clipped. */
            const above = point.y - chartArea.top > 24;

            ctx.save();
            /* A resolved family, not `var(--font-stack)`: a canvas font string
               is parsed without a cascade to resolve a custom property
               against, and an unparseable one is dropped silently. */
            ctx.font = `600 12px ${fontFamily()}`;
            ctx.fillStyle = colour;
            ctx.textAlign = 'center';
            ctx.textBaseline = above ? 'bottom' : 'top';
            ctx.fillText(displays[index], point.x, point.y + (above ? -10 : 10));
            ctx.restore();
        },
    };
}

/** The page's own family, resolved, for the one string drawn onto the canvas. */
function fontFamily() {
    const family = getComputedStyle(document.documentElement).fontFamily;

    return family === '' ? 'system-ui, sans-serif' : family;
}

function configFor(data) {
    const values = data.months.map((month) => month.minor);
    const displays = data.months.map((month) => month.display);
    const committed = data.months.map((month) => month.committed_minor);
    const tickLabels = new Map(data.ticks.map((tick) => [tick.value, tick.label]));

    const total = token('--accent', '#086f66');
    /* Amber is this application's "money is about to move", and a trial about
       to convert is exactly that — so the upper line and the band it encloses
       are amber, and the spend already committed is the calm accent. */
    const trial = token('--warning', '#a83f0e');
    const grid = token('--border', 'rgba(16, 24, 40, 0.12)');
    const text = token('--text-muted', '#5b6472');
    const surface = token('--surface', '#ffffff');
    /* The wash between the two lines, as its own token rather than the upper
       line's colour thinned here: the amount of it that reads as the same wash
       is not the same on a light surface as on a dark one, and that is a
       decision for the palette to make. */
    const band = token('--chart-band', 'rgba(168, 63, 14, 0.16)');

    const partialIndex = data.partial_index ?? -1;
    /* The segment touching the part month is drawn dashed, whichever side of it
       that is: a forecast's part month is its first and only has a segment
       leading out of it, a history's is its last and only has one leading in.
       Testing both ends is what makes one rule serve both charts — matching on
       the start of a segment alone would leave the history's final leg solid,
       which is the leg drawn from the figure that is short. */
    const dashPartial = (ctx) =>
        (partialIndex !== -1 && (ctx.p0DataIndex === partialIndex || ctx.p1DataIndex === partialIndex)
            ? [4, 4]
            : undefined);

    const line = {
        borderWidth: 2,
        /* Straight segments, not a curve. A month's spend is a total, not a
           sample of something continuous, and a spline drawn through twelve
           totals invents the eleven slopes between them: a yearly bill in
           February becomes a hill that starts rising in December, which is a
           claim about December that the data does not make. */
        tension: 0,
        pointRadius: 0,
        pointHoverRadius: PEAK_RADIUS,
        pointBorderWidth: 2,
        /* A ring in the surface colour, so a point sitting on top of the other
           line stays legible as a separate mark. */
        pointHoverBorderColor: surface,
    };

    const datasets = [{
        ...line,
        label: data.has_trials
            ? window.renovoI18n.t('dashboard.spend_with_trials')
            : window.renovoI18n.t('dashboard.spend'),
        data: values,
        borderColor: data.has_trials ? trial : total,
        backgroundColor: data.has_trials ? trial : total,
        pointHoverBackgroundColor: data.has_trials ? trial : total,
        /* Only meaningful with something to fill down to. A fill targeting a
           dataset that was never added draws nothing and says nothing. */
        fill: data.has_trials ? { target: 1, above: band, below: band } : false,
        segment: { borderDash: dashPartial },
        /* The peak wears a marker; every other point appears on hover. */
        pointRadius: values.map((_, index) => (index === data.peak_index ? PEAK_RADIUS : 0)),
    }];

    if (data.has_trials) {
        datasets.push({
            ...line,
            label: window.renovoI18n.t('dashboard.spend_committed'),
            data: committed,
            borderColor: total,
            backgroundColor: total,
            pointHoverBackgroundColor: total,
            fill: false,
            segment: { borderDash: (ctx) => dashPartial(ctx) ?? [6, 3] },
            borderDash: [6, 3],
        });
    }

    return {
        type: 'line',
        data: {
            labels: data.months.map((month) => month.label),
            datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            /* The canvas carries an aria-label and the table beside it carries
               the figures, so the library's own accessibility tree adds
               nothing a screen reader has not already been given. */
            animation: { duration: 200 },
            /* A line is read by month, not by point: hovering anywhere in a
               column reports every series in it, which is also what makes the
               difference between them reportable at all. */
            interaction: { mode: 'index', intersect: false },
            plugins: {
                /* The legend is rendered in the template, in the same ICU the
                   rest of the page is formatted with, and stays visible for a
                   reader who only ever sees the figures table. */
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        /* The formatted strings the server produced, not
                           numbers this file would have to format. */
                        label: (context) => {
                            const month = data.months[context.dataIndex];
                            const amount = context.datasetIndex === 0
                                ? month.display
                                : month.committed_display;

                            return `${context.dataset.label}: ${amount}`;
                        },
                        afterBody: (items) => {
                            const month = data.months[items[0].dataIndex];
                            if (!data.has_trials || month.trial_minor === 0) {
                                return '';
                            }

                            return window.renovoI18n.t('dashboard.trial_gap', {
                                amount: month.trial_display,
                            });
                        },
                        title: (items) => {
                            const month = data.months[items[0].dataIndex];

                            return month.is_partial
                                ? `${month.label} · ${window.renovoI18n.t('dashboard.part_month')}`
                                : month.label;
                        },
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
        plugins: [
            crosshairFor(grid),
            peakLabelFor(data.peak_index, displays, token('--text', '#101828')),
        ],
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
