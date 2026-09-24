/**
 * The theme stylesheet, generated from `tokens.json`.
 *
 * The tokens are authored once, as data, because two things have to read them
 * and agree: this, which turns them into CSS, and the PHPUnit contrast test,
 * which checks them against WCAG. Hand-written CSS could only be checked by
 * parsing it back, and a checker that parses the thing it checks is a checker
 * that agrees with whatever it was given.
 *
 * Every palette × theme is written out *resolved*: each block carries the
 * full set of properties, with every "@reference" replaced by its literal
 * value. That costs a few kilobytes and buys two things. A chart reading
 * `--s2` through getComputedStyle gets a colour rather than a `var()` it
 * cannot parse; and no block depends on another having been applied first, so
 * the only cascade question left is "is the dark block more specific than the
 * light one", which the selectors below answer by construction.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

/** The palette an account without a choice gets, and the one signed-out pages use. */
export const DEFAULT_PALETTE = 'navy';

/**
 * One palette in one theme, resolved to literal values.
 *
 * The order is the contract, and `ThemeContrastTest` resolves with the same
 * one: base light, then base dark, then the palette's light values, then its
 * dark ones — later wins.
 *
 * @param {object} tokens  The parsed tokens.json.
 * @param {string} palette A key of `tokens.palettes`.
 * @param {'light'|'dark'} theme
 * @returns {Record<string, string>}
 */
export function resolve(tokens, palette, theme) {
    const set = tokens.palettes[palette];
    const layers = theme === 'dark'
        ? [tokens.base.light, tokens.base.dark, set.light, set.dark]
        : [tokens.base.light, set.light];

    const merged = Object.assign({}, ...layers);

    for (const [name, value] of Object.entries(merged)) {
        merged[name] = dereference(merged, name, value, []);
    }

    return merged;
}

function dereference(merged, name, value, seen) {
    if (typeof value !== 'string' || !value.startsWith('@')) {
        return value;
    }

    const target = value.slice(1);

    if (seen.includes(target) || !(target in merged)) {
        throw new Error(`Token "${name}" refers to "${value}", which ${seen.includes(target) ? 'is circular' : 'does not exist'}.`);
    }

    return dereference(merged, target, merged[target], [...seen, name]);
}

function declarations(values) {
    return Object.entries(values)
        .map(([name, value]) => (name === 'color-scheme' ? `color-scheme: ${value};` : `--${name}: ${value};`))
        .join('\n    ');
}

function block(selector, values) {
    return `${selector} {\n    ${declarations(values)}\n}`;
}

/**
 * The stylesheet, as a string.
 *
 * The default palette's blocks are on the bare `:root` (light) and the bare
 * theme selector (dark), so a page that somehow renders without a
 * `data-palette` still gets a complete, readable palette. Every other
 * palette's blocks add `[data-palette=…]` and therefore outrank them.
 *
 * Dark is written twice, as the theme has three states and only two of them
 * are a media query's business: once for `system` on a machine that asks for
 * dark — guarded by `:not([data-theme=light])`, so an explicit "light" on a
 * dark machine stays light — and once for an explicit `dark`.
 *
 * @param {object} tokens The parsed tokens.json.
 * @returns {string}
 */
export function generateThemeCss(tokens) {
    const palettes = Object.keys(tokens.palettes);

    if (!palettes.includes(DEFAULT_PALETTE)) {
        throw new Error(`tokens.json has no "${DEFAULT_PALETTE}" palette, which is the default.`);
    }

    const scoped = (prefix, palette) => (palette === DEFAULT_PALETTE ? prefix : `${prefix}[data-palette='${palette}']`);
    const ordered = [DEFAULT_PALETTE, ...palettes.filter((key) => key !== DEFAULT_PALETTE)];

    const light = ordered.map((key) => block(scoped(':root', key), resolve(tokens, key, 'light')));
    const system = ordered.map((key) => block(scoped(":root:not([data-theme='light'])", key), resolve(tokens, key, 'dark')));
    const dark = ordered.map((key) => block(scoped(":root[data-theme='dark']", key), resolve(tokens, key, 'dark')));

    // What the palette picker draws each option with: the rail and the accent
    // the option would give, as the light theme shows them. Scoped to the
    // swatch rather than the page, so every option can show its own colours
    // while the page wears one.
    const swatches = ordered.map((key) => {
        const values = resolve(tokens, key, 'light');

        return `[data-swatch='${key}'] {\n    --swatch-rail: ${values.rail};\n    --swatch-accent: ${values.accent};\n    --swatch-border: ${values['rail-border'] === 'transparent' ? values.rail : values['rail-border']};\n}`;
    });

    return [
        '/*',
        ' * GENERATED from assets/theme/tokens.json by assets/theme/build-tokens.js.',
        ' * Do not edit: change the JSON, and the build rewrites this file.',
        ' */',
        '',
        ...light,
        '',
        '@media (prefers-color-scheme: dark) {',
        ...system.map((css) => css.replace(/^/gm, '    ')),
        '}',
        '',
        ...dark,
        '',
        ...swatches,
        '',
    ].join('\n');
}

/**
 * The Vite plugin: regenerate the stylesheet whenever the build starts.
 *
 * It writes a real file that `app.css` imports, rather than a virtual module,
 * so Tailwind's own plugin sees it as ordinary CSS and an operator reading the
 * source tree can open the thing that was compiled. The file is gitignored.
 *
 * Written only when its content changes: it sits under `assets/css/`, which
 * the watcher follows, and rewriting an identical file on every rebuild would
 * trigger the rebuild that rewrites it.
 *
 * @param {{ source: string, output: string }} paths Absolute paths.
 */
export function themeTokens({ source, output }) {
    return {
        name: 'renovo:theme-tokens',
        buildStart() {
            this.addWatchFile(source);

            const css = generateThemeCss(JSON.parse(readFileSync(source, 'utf8')));

            if (existsSync(output) && readFileSync(output, 'utf8') === css) {
                return;
            }

            mkdirSync(dirname(output), { recursive: true });
            writeFileSync(output, css);
        },
    };
}
