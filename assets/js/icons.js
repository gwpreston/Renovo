/**
 * The icon set.
 *
 * Lucide ships a thousand icons as individual modules, so the ones named here
 * are the only ones bundled — importing the package's index instead would put
 * every icon in the bundle to use eight of them.
 *
 * Adding an icon is adding a line to this map. There is no lazy path and does
 * not need one: each icon is a few hundred bytes of path data.
 */

import {
    BellRing,
    CalendarDays,
    ChartLine,
    CircleAlert,
    CreditCard,
    Plus,
    Settings,
    Tag,
    Wallet,
    createElement,
} from 'lucide';

const ICONS = {
    alert: CircleAlert,
    budget: Wallet,
    calendar: CalendarDays,
    card: CreditCard,
    forecast: ChartLine,
    notification: BellRing,
    add: Plus,
    settings: Settings,
    tag: Tag,
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
