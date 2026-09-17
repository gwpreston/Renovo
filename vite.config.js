import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

/**
 * The asset build.
 *
 * Everything the browser loads is produced here and served from this
 * instance's own web root. Build time may use the network — npm pulls packages
 * the same way Composer pulls PHP ones — but run time may not: an instance on
 * a LAN or behind Tailscale may have no route to a CDN at all, so a page that
 * depends on one is a page that breaks in exactly the deployment this
 * application is built for.
 *
 * The source (this file, `assets/`, `node_modules/`) sits outside `public/`
 * and is unreachable over HTTP. Only the output is served.
 */

const root = import.meta.dirname;

/**
 * The Inter licence, vendored alongside the font it covers.
 *
 * Fontsource repackages Google's families for self-hosting; the font is still
 * Google's and still under the SIL Open Font License, and shipping the .woff2
 * without the licence text beside it would not honour it. Copied out of
 * node_modules at build time rather than committed, so it can never drift from
 * the version actually being served.
 */
function vendorFontLicence() {
    const candidates = ['LICENSE', 'LICENSE.md', 'LICENSE.txt'];

    return {
        name: 'renovo:vendor-font-licence',
        // `closeBundle` rather than `generateBundle`: emptyOutDir has already
        // run by then, so the file cannot be swept away by the build that is
        // meant to produce it.
        closeBundle() {
            const from = candidates
                .map((name) => resolve(root, 'node_modules/@fontsource-variable/inter', name))
                .find((path) => existsSync(path));

            if (from === undefined) {
                this.warn('No licence file found in @fontsource-variable/inter.');

                return;
            }

            const to = resolve(root, 'public/build/inter-OFL.txt');
            mkdirSync(dirname(to), { recursive: true });
            copyFileSync(from, to);
        },
    };
}

export default defineConfig({
    root,
    plugins: [tailwindcss(), vendorFontLicence()],

    // The URL prefix the output is served under, which has to match both the
    // nginx location block and the Twig helper. Without it the @font-face
    // rules Vite rewrites would point at `/inter-….woff2` — the right file
    // under the wrong path, and the one third-party-looking 404 this phase
    // exists to make impossible.
    base: '/build/',

    // Vite's "public directory" — a folder whose contents are copied verbatim
    // into the output — is off. Its default is `<root>/public`, which here is
    // the web root: left on, every build would copy the served stylesheet, the
    // vendored htmx and every uploaded logo into `public/build/`.
    publicDir: false,

    build: {
        // A directory of its own, never `public/` or `public/assets/`: Vite
        // empties its output directory on every build, and `public/assets/`
        // holds both the committed pre-build files and the logos volume that
        // users upload into.
        outDir: 'public/build',
        // Flat, so a hashed file is `/build/app-4f2a1c.css` rather than
        // `/build/assets/app-4f2a1c.css`.
        assetsDir: '.',
        // Named explicitly. The default is `.vite/manifest.json`, and nginx
        // denies any path with a dot-segment in it — harmless, since PHP reads
        // this off disk, but a needless thing to have to remember.
        manifest: 'manifest.json',
        emptyOutDir: true,
        // A self-hosted instance is not chasing a Lighthouse score, and an
        // operator reading the shipped CSS to work out why their page looks
        // wrong is a real scenario. Sourcemaps cost bytes nobody downloads
        // unless they open devtools.
        sourcemap: true,

        // A list rather than a name-to-path map, so each entry is named after
        // its own file: `app.js` compiles to `app-<hash>.js` and `app.css` to
        // `app-<hash>.css`. A map would need two distinct keys and one of the
        // two outputs would end up called something the template does not ask
        // for.
        rollupOptions: {
            input: [
                resolve(root, 'assets/js/app.js'),
                resolve(root, 'assets/css/app.css'),
            ],
        },
    },
});
