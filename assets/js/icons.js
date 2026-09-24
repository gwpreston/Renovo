/**
 * Icons, for the few places a script builds markup of its own.
 *
 * Everything the server renders gets its icons from the Twig `icon()`
 * function, which points a `<use>` at the sprite the build produced from
 * `assets/theme/icons.json`. A script that draws a badge after the page has
 * loaded — the payment-method preview — does the same thing here, against the
 * same sprite, so there is one set of drawings and no copy of Lucide in the
 * bundle.
 *
 * The sprite's URL is content-hashed, so the layout hands it over on the root
 * element as `data-icon-sprite` rather than this file guessing it.
 */

const SVG = 'http://www.w3.org/2000/svg';

/**
 * An icon as an `<svg>` element, or null when the page carries no sprite.
 *
 * An element rather than a string of markup, so a caller cannot put it on a
 * page with `innerHTML`. Icons are decoration, so the element is hidden from
 * assistive technology — whatever the icon sits beside carries the meaning.
 *
 * @param {string} name A key of `assets/theme/icons.json`.
 * @param {string} [className] A class for the `<svg>`, which is what sizes it.
 * @returns {SVGElement|null}
 */
export function icon(name, className = 'icon') {
    const sprite = document.documentElement.dataset.iconSprite;

    if (!sprite) {
        return null;
    }

    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('class', className);
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');

    const use = document.createElementNS(SVG, 'use');
    use.setAttribute('href', `${sprite}#${name}`);
    svg.append(use);

    return svg;
}
