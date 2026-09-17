/**
 * The icon set.
 *
 * Lucide ships a thousand icons as individual modules, so the ones named here
 * are the only ones bundled — importing the package's index instead would put
 * every icon in the bundle to use seventeen of them.
 *
 * Adding an icon is adding a line to this map. There is no lazy path and does
 * not need one: each icon is a few hundred bytes of path data.
 *
 * The names are what a thing is in this application, not what Lucide calls the
 * drawing. `subscriptions` is the navigation's second item; which glyph that
 * happens to be is this file's business and nowhere else's.
 */

import {
    BellRing,
    CalendarDays,
    CalendarX2,
    ChartLine,
    ChartNoAxesColumn,
    CircleAlert,
    CircleUserRound,
    CreditCard,
    Ellipsis,
    LayoutDashboard,
    Plus,
    ScrollText,
    Search,
    Settings,
    Tag,
    Upload,
    Wallet,
    createElement,
} from 'lucide';

const ICONS = {
    add: Plus,
    alert: CircleAlert,
    analytics: ChartNoAxesColumn,
    audit: ScrollText,
    budget: Wallet,
    calendar: CalendarDays,
    cancellations: CalendarX2,
    categories: Tag,
    dashboard: LayoutDashboard,
    forecast: ChartLine,
    import: Upload,
    more: Ellipsis,
    notifications: BellRing,
    profile: CircleUserRound,
    search: Search,
    settings: Settings,
    subscriptions: CreditCard,
};

/**
 * An icon as an `<svg>` element, or null if there is no icon by that name.
 *
 * An element rather than a string of markup, so a caller cannot put it on a
 * page with `innerHTML` and cannot be tempted to interpolate anything into it.
 * Icons are decoration, so the element is hidden from assistive technology —
 * whatever the icon sits beside carries the meaning.
 *
 * @param {string} name A key of the map above.
 * @returns {SVGElement|null}
 */
export function icon(name) {
    const definition = ICONS[name];

    if (definition === undefined) {
        return null;
    }

    const element = createElement(definition);
    element.setAttribute('aria-hidden', 'true');
    element.setAttribute('focusable', 'false');

    return element;
}

/** The names `icon()` will answer to. */
export function iconNames() {
    return Object.keys(ICONS);
}

/**
 * Fill every `<span data-icon="…">` under `root` with its drawing.
 *
 * The shell's navigation marks its icon slots in the HTML and lets this put
 * the pictures in, rather than the server inlining path data it would have to
 * keep in step with this map by hand. Nothing depends on it running: each slot
 * sits beside the label that says where the link goes, the stylesheet reserves
 * the space either way, and a browser with the bundle blocked gets navigation
 * with no pictures rather than navigation that has moved.
 *
 * Idempotent, so it can be called again after htmx has swapped in new markup.
 *
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function hydrateIcons(root = document) {
    root.querySelectorAll('[data-icon]:empty').forEach((slot) => {
        const drawing = icon(slot.dataset.icon);

        if (drawing !== null) {
            slot.append(drawing);
        }
    });
}
