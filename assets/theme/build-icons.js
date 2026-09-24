/**
 * The icon sprite, built from `icons.json` and the Lucide package.
 *
 * One SVG file of `<symbol>`s, one per icon the application names, emitted
 * into the build under a content hash. A template draws an icon with
 * `icon('wallet')`, which prints `<svg><use href="/build/sprite-….svg#wallet">`
 * — server-rendered, so the navigation has its pictures with JavaScript off
 * and no path data has to be kept in step between PHP and a script.
 *
 * Beside it goes `icons.json` (unhashed, so PHP can find it): the sprite's
 * hashed filename and the list of names in it. `icon()` checks a name against
 * that list, which is how an unknown one fails loudly rather than drawing an
 * empty square.
 *
 * Only the icons listed are read out of Lucide, so the thousand the package
 * ships stay in node_modules.
 */

import { readFileSync } from 'node:fs';
import * as lucide from 'lucide';

const escape = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/"/g, '&quot;');

/** Lucide's canonical names are kebab-case; its exports are PascalCase. */
const exportName = (kebab) => kebab.replace(/(^|-)([a-z0-9])/g, (_, __, char) => char.toUpperCase());

/**
 * The sprite's markup.
 *
 * The drawing attributes are on a `<g>` inside each symbol rather than on the
 * symbol itself, so they reach the paths the same way in every browser's
 * `<use>` shadow tree. `currentColor` means an icon takes the colour of the
 * text it sits in.
 *
 * @param {Record<string, string>} icons Application name => Lucide name.
 * @returns {string}
 */
export function buildSprite(icons) {
    const symbols = Object.entries(icons).map(([name, drawing]) => {
        const node = lucide[exportName(drawing)];

        if (!Array.isArray(node)) {
            throw new Error(`icons.json: "${name}" names the Lucide icon "${drawing}", which does not exist.`);
        }

        const children = node
            .map(([tag, attributes]) => `<${tag} ${Object.entries(attributes)
                .map(([key, value]) => `${key}="${escape(value)}"`)
                .join(' ')}/>`)
            .join('');

        return `<symbol id="${escape(name)}" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${children}</g></symbol>`;
    });

    return `<svg xmlns="http://www.w3.org/2000/svg">${symbols.join('')}</svg>\n`;
}

/**
 * The Vite plugin.
 *
 * `originalFileName` is what puts the sprite in the manifest, under the key
 * `assets/theme/sprite.svg`, alongside the fonts: the manifest is the record
 * of every file the build produced.
 *
 * @param {{ source: string }} paths Absolute path to icons.json.
 */
export function iconSprite({ source }) {
    return {
        name: 'renovo:icon-sprite',
        buildStart() {
            this.addWatchFile(source);
        },
        generateBundle() {
            const { icons } = JSON.parse(readFileSync(source, 'utf8'));

            const sprite = this.emitFile({
                type: 'asset',
                name: 'sprite.svg',
                originalFileName: 'assets/theme/sprite.svg',
                source: buildSprite(icons),
            });

            this.emitFile({
                type: 'asset',
                fileName: 'icons.json',
                source: `${JSON.stringify({ sprite: this.getFileName(sprite), names: Object.keys(icons).sort() }, null, 2)}\n`,
            });
        },
    };
}
