<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\BuildManifest;
use PHPUnit\Framework\TestCase;

/**
 * The design system, as the browser actually receives it.
 *
 * Phase 8's promises are mostly visual, but they are not untestable: they are
 * claims about what the compiled stylesheet contains. This reads the real
 * output of `npm run build` — the same file a page links — and checks the
 * things that would otherwise only be noticed by a person looking at a screen,
 * or worse, not noticed at all:
 *
 *   - the brand gradient is the logo's two stops, written down once;
 *   - all three theme states exist, including the one an explicit "light"
 *     setting needs on a dark machine;
 *   - Inter is applied, with tabular figures on the money;
 *   - every text colour the palette defines clears WCAG AA on the surface it
 *     is used against, in both themes.
 *
 * Skipped when there is no build, so `vendor/bin/phpunit` still runs on a
 * checkout where `npm install` has not been. CI runs the build first, so there
 * it is never skipped.
 */
final class DesignTokensTest extends TestCase
{
    /**
     * The colours a stylesheet other than `tokens.css` is allowed to write.
     *
     * One entry, and it is documented where it is written: the QR code's plate
     * stays white on the dark theme because a camera reads dark modules on a
     * light field.
     *
     * @var array<string, list<string>>
     */
    private const COLOUR_EXCEPTIONS = ['screens.css' => ['#ffffff']];

    /** WCAG 2.1 AA: normal-sized body text against its background. */
    private const AA_TEXT = 4.5;

    /** WCAG 2.1 AA: the visible boundary of a user-interface component. */
    private const AA_NON_TEXT = 3.0;

    /**
     * The categorical palette: the colours a chart tells one series from
     * another by. Six, plus the tail that "everything else" is drawn in.
     */
    private const SERIES = [
        '--series-1',
        '--series-2',
        '--series-3',
        '--series-4',
        '--series-5',
        '--series-6',
        '--series-other',
    ];

    private string $css;

    protected function setUp(): void
    {
        $buildPath = dirname(__DIR__, 2) . '/public/build';
        $build = new BuildManifest($buildPath . '/manifest.json', '/build/');

        if (!$build->isBuilt()) {
            self::markTestSkipped('No asset build present. Run "npm install && npm run build".');
        }

        $file = $buildPath . '/' . basename($build->url('app.css'));
        $this->css = (string) file_get_contents($file);
    }

    // ------------------------------------------------------------- the brand

    /**
     * The gradient stops are the logo's, and they are written down once.
     *
     * These are the mark's real colours, sampled from `assets/brand/` in
     * Phase 9 when the logo arrived — Phase 8 had to write down its own stated
     * values because the file was not in the repository. Every gradient in the
     * application composes from these two names. A literal colour appearing in
     * a gradient somewhere else is how an interface ends up with three slightly
     * different greens.
     */
    public function testTheBrandGradientStopsAreDefinedAsTokens(): void
    {
        self::assertSame('#0daa9c', $this->token(':root', '--brand-from'), 'The teal-green stop moved.');
        self::assertSame('#1069bb', $this->token(':root', '--brand-to'), 'The blue stop moved.');

        self::assertStringContainsString(
            'var(--brand-from)',
            $this->css,
            'The brand gradient must compose from the stop tokens, not repeat their values.',
        );
    }

    /**
     * The filled primary button is a solid colour, and its ink is readable on
     * it in both themes.
     *
     * It was a gradient until the button was flattened, and the reason the
     * gradient existed is the reason this test still does: white on the mark's
     * teal stop is 2.9:1. The fill is `--accent` with `--accent-text` on it,
     * and this is what notices if either is nudged towards the logo's own
     * colours, where the pair would stop being readable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testTheFilledButtonCarriesItsInk(string $theme): void
    {
        $fill = $this->token($theme, '--accent');
        $ink = $this->token($theme, '--accent-text');

        self::assertNotNull($fill, 'No accent colour in this palette.');
        self::assertNotNull($ink, 'No accent ink in this palette.');

        self::assertGreaterThanOrEqual(
            self::AA_TEXT,
            $this->contrast($ink, $fill),
            sprintf('The primary button fails AA (%s on %s).', $ink, $fill),
        );
    }

    /**
     * The filled button is flat: nothing paints it with a gradient.
     *
     * Asserted against the compiled stylesheet rather than the source, because
     * what a browser receives is the only version that matters.
     */
    public function testTheFilledButtonIsNotAGradient(): void
    {
        self::assertMatchesRegularExpression(
            '~\.button-primary\{[^}]*background:var\(--accent\)~',
            $this->css,
            'The primary button should be filled with the flat accent colour.',
        );
        self::assertDoesNotMatchRegularExpression(
            '~\.button-primary\{[^}]*linear-gradient~',
            $this->css,
            'The primary button is a solid colour, not a gradient.',
        );
    }


    // ------------------------------------------------------------- the themes

    /**
     * All three theme states, which is one more than a media query provides.
     *
     * An account that has explicitly chosen light, on a machine set to dark,
     * must get light. That needs the system-dark rule to exclude it — the
     * `:not([data-theme=light])` guard — and it is the case that silently
     * breaks, because the person who chose the setting is exactly the person
     * whose machine disagrees with it.
     */
    public function testBothThemesAreDefinedAndAnExplicitChoiceBeatsTheSystem(): void
    {
        self::assertNotNull($this->token(':root', '--surface'), 'No light palette.');
        self::assertNotNull($this->token(':root[data-theme=dark]', '--surface'), 'No explicit dark palette.');

        self::assertMatchesRegularExpression(
            '~@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root:not\(\[data-theme=["\']?light["\']?\]\)~',
            $this->css,
            'System-dark must not repaint an account that has explicitly chosen light.',
        );
    }

    /** The two palettes are different, or one of them was not written. */
    public function testTheTwoThemesAreActuallyDifferentPalettes(): void
    {
        self::assertNotSame(
            $this->token(':root', '--surface'),
            $this->token(':root[data-theme=dark]', '--surface'),
            'Light and dark resolve to the same surface.',
        );
    }

    // --------------------------------------------------------------- the type

    /** Phase 7 vendored Inter and defined the token; Phase 8 applies it. */
    public function testInterIsAppliedAndNotMerelyServed(): void
    {
        self::assertStringContainsString('Inter Variable', $this->css, 'Inter is not in the font stack.');
        self::assertMatchesRegularExpression(
            '~html\{[^}]*font-family:var\(--font-stack\)~',
            $this->css,
            'The font token is defined but nothing applies it.',
        );
    }

    /**
     * The reason the typeface is Inter.
     *
     * Proportional digits are different widths, so a column of amounts stops
     * lining up as the values change. `tabular-nums` is what removes that, and
     * a money application that lost it would be harder to read in a way nobody
     * would think to file a bug about.
     */
    public function testFiguresAreTabular(): void
    {
        self::assertStringContainsString('font-variant-numeric:tabular-nums', $this->css);

        preg_match_all('~([^{}]*)\{font-variant-numeric:tabular-nums\}~', $this->css, $matches);
        $selectors = implode(',', $matches[1]);

        foreach (['.table', '.stat-value', '.timeline-amount', '.calendar-amount', '.money'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $selectors,
                sprintf('Figures in "%s" are not tabular.', $selector),
            );
        }
    }

    /**
     * `font-feature-settings: "tnum"` would switch off every other feature in
     * the font — Inter's subset also carries calt, frac, numr and dnom — so
     * the high-level property is the correct one and the low-level one is a
     * regression waiting to be pasted in from a blog post.
     */
    public function testTabularFiguresDoNotClobberTheFontsOtherFeatures(): void
    {
        // Preflight sets `font-feature-settings` from its own defaults, which is
        // not what this is about: what must not appear is the numeric feature
        // being driven by the low-level property.
        self::assertDoesNotMatchRegularExpression(
            '~font-feature-settings:[^;}]*tnum~',
            $this->css,
        );
    }

    // ------------------------------------------------------------- Preflight

    /**
     * Preflight is on, and the defaults the templates rely on came back.
     *
     * Turning the reset on without re-establishing these is how every heading
     * on every page silently becomes body text.
     */
    public function testPreflightIsOnAndTheDefaultsItStripsAreRestored(): void
    {
        self::assertStringContainsString('*,:after,:before', $this->css, 'Preflight is not in the build.');

        $restored = [
            '~[,{}]h1\{font-size:~' => 'Headings lost their size.',
            '~a\{[^}]*text-decoration:underline~' => 'Links lost their underline.',
            '~:focus-visible\{[^}]*outline:~' => 'Nothing shows keyboard focus.',
        ];

        foreach ($restored as $pattern => $message) {
            self::assertMatchesRegularExpression($pattern, $this->css, $message);
        }
    }

    // -------------------------------------------------------------- contrast

    /**
     * Every text colour clears AA on every surface it is actually drawn on.
     *
     * The palette is the thing that decides whether the application is
     * readable, so it is the thing worth locking down. A token nudged a few
     * points darker to "look better" is exactly the change that passes review
     * and fails a reader.
     *
     * The matrix is the point. Checking each ink against `--surface` alone
     * proves the card and nothing else, and the places contrast quietly fails
     * are the ones nobody pictures while choosing a colour: the quiet text in
     * the footer, which sits on the page rather than on a card; the same text
     * in a hovered row, which is a surface lighter than the one it was chosen
     * against; and the ordinary ink in a row tinted for urgency, where the
     * background was picked to carry the warning colour and then has to carry
     * the name of the subscription too.
     *
     * Every pair below is one a rule in `components.css` or `screens.css`
     * genuinely produces. A pair that stops being real should be deleted from
     * here rather than left to pass.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function textPairs(): iterable
    {
        $themes = [':root' => 'light', ':root[data-theme=dark]' => 'dark'];

        /*
         * The surfaces ordinary text is set on: the page itself, a card, the
         * raised chrome (rail, top bar, bottom bar, drawer), the sunken fill
         * (fields, chips, `kbd`, a calendar entry), the hover lift a table row
         * and a nav item take, and the two tints a row wears when a deadline
         * is close or past.
         */
        $everySurface = [
            '--bg',
            '--surface',
            '--surface-raised',
            '--surface-sunken',
            '--surface-hover',
            '--warning-bg',
            '--error-bg',
            '--success-bg',
        ];

        /* The coloured inks are used inside cards and on controls, not on a tint. */
        $plainSurfaces = ['--bg', '--surface', '--surface-raised', '--surface-sunken', '--surface-hover'];

        $pairs = [
            '--text' => $everySurface,
            '--text-muted' => $everySurface,
            '--accent' => $plainSurfaces,
            '--danger' => $plainSurfaces,
            '--increase' => ['--surface'],
            '--decrease' => ['--surface'],
            '--warning' => [...$plainSurfaces, '--warning-bg'],
            '--error-text' => ['--error-bg'],
            '--success-text' => ['--success-bg'],
        ];

        foreach ($themes as $selector => $theme) {
            foreach ($pairs as $ink => $grounds) {
                foreach ($grounds as $ground) {
                    yield sprintf('%s: %s on %s', $theme, $ink, $ground) => [$selector, $ink, $ground];
                }
            }
        }
    }

    /**
     * @dataProvider textPairs
     */
    public function testEveryTextColourClearsAa(string $theme, string $ink, string $ground): void
    {
        $foreground = $this->token($theme, $ink);
        $background = $this->token($theme, $ground);

        self::assertNotNull($foreground, sprintf('%s is not defined for %s.', $ink, $theme));
        self::assertNotNull($background, sprintf('%s is not defined for %s.', $ground, $theme));

        $ratio = $this->contrast($foreground, $background);

        self::assertGreaterThanOrEqual(
            self::AA_TEXT,
            $ratio,
            sprintf('%s on %s is %.2f:1, under AA.', $foreground, $background, $ratio),
        );
    }

    /**
     * The edge of an input is a component boundary, which WCAG holds to 3:1.
     *
     * The hairline that separates cards is deliberately fainter than this and
     * is not held to it — it separates things that are already distinct. The
     * border on a field is the only thing telling a reader where to type.
     *
     * @return iterable<string, array{string}>
     */
    public static function themes(): iterable
    {
        yield 'light' => [':root'];
        yield 'dark' => [':root[data-theme=dark]'];
    }

    /**
     * @dataProvider themes
     */
    public function testControlBordersAreVisibleEnoughToFindAField(string $theme): void
    {
        $border = $this->token($theme, '--border-control');
        $surface = $this->token($theme, '--surface');

        self::assertNotNull($border);
        self::assertNotNull($surface);

        $ratio = $this->contrast($border, $surface);

        self::assertGreaterThanOrEqual(
            self::AA_NON_TEXT,
            $ratio,
            sprintf('A field border at %.2f:1 is effectively invisible.', $ratio),
        );
    }

    /**
     * No stylesheet but the token file writes a colour of its own.
     *
     * This is what makes "viewed in light and in dark" a claim about every
     * screen rather than about the screens somebody remembered to open. If a
     * colour only ever arrives through a custom property, and both palettes
     * assign every property, and the contrast matrix above covers both — then
     * a screen cannot be right in one theme and wrong in the other. One hard
     * `#hex` in a component rule breaks that chain silently: it looks correct
     * on whichever theme its author had on screen.
     *
     * The single exception is the QR code's plate, which is white in both
     * themes on purpose — a camera looks for dark modules on a light field,
     * and inverting it makes the code unscannable.
     *
     * @dataProvider styleSheets
     */
    public function testOnlyTheTokenFileWritesAColour(string $file): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/' . $file);

        // Comments are where the palette is explained, hex codes and all.
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);

        $matches = [];
        preg_match_all('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $source, $matches);

        self::assertSame(
            self::COLOUR_EXCEPTIONS[$file] ?? [],
            $matches[0],
            sprintf(
                '%s writes a colour literal. Every colour is a token in tokens.css, which is what '
                . 'lets one set of rules carry both themes.',
                $file,
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function styleSheets(): iterable
    {
        yield 'base.css' => ['base.css'];
        yield 'components.css' => ['components.css'];
        yield 'screens.css' => ['screens.css'];
    }

    // ------------------------------------------------------- the series palette

    /**
     * The categorical palette exists in both themes and is the same size in
     * each.
     *
     * A chart reads its segment colours from these at the moment it is drawn,
     * so a series defined in light and missing in dark is a donut that loses a
     * segment when the machine flips at sunset — and loses it silently, because
     * the fallback in the bundle keeps drawing *something*.
     *
     * @dataProvider themes
     */
    public function testTheSeriesPaletteIsCompleteInBothThemes(string $theme): void
    {
        foreach (self::SERIES as $name) {
            self::assertNotNull(
                $this->token($theme, $name),
                sprintf('%s is not defined for %s.', $name, $theme),
            );
        }
    }

    /**
     * No two series are the same colour.
     *
     * The entire job of a categorical palette is to tell one category from
     * another. Two entries that resolve to the same value is a donut with a
     * segment nobody can find, and it is the kind of thing a copied-and-pasted
     * line produces without looking wrong in the source.
     *
     * @dataProvider themes
     */
    public function testEverySeriesIsDistinguishableFromEveryOther(string $theme): void
    {
        $seen = [];

        foreach (self::SERIES as $name) {
            $value = strtolower((string) $this->token($theme, $name));

            self::assertNotContains(
                $value,
                $seen,
                sprintf('%s repeats a colour already in the palette (%s).', $name, $value),
            );

            $seen[] = $value;
        }
    }

    /**
     * A segment is a graphical object, which WCAG holds to 3:1 against what it
     * sits on.
     *
     * The donut is drawn on a card, so `--surface` is what each segment has to
     * be findable against. A hue that fails this is one a reader cannot see is
     * there at all, which is worse than a hue they cannot tell from its
     * neighbour.
     *
     * @dataProvider themes
     */
    public function testEverySeriesIsVisibleAgainstTheCardItIsDrawnOn(string $theme): void
    {
        $surface = $this->token($theme, '--surface');
        self::assertNotNull($surface);

        foreach (self::SERIES as $name) {
            $colour = $this->token($theme, $name);
            self::assertNotNull($colour);

            $ratio = $this->contrast($colour, $surface);

            self::assertGreaterThanOrEqual(
                self::AA_NON_TEXT,
                $ratio,
                sprintf('%s is %.2f:1 on the card and effectively invisible.', $name, $ratio),
            );
        }
    }

    // ----------------------------------------------------------------- tools

    /**
     * The value of a custom property in a selector's blocks.
     *
     * The compiled stylesheet is minified and a selector may have several
     * blocks — the tokens are grouped by what they are for, not crammed into
     * one rule — so every block for the selector is read and the last
     * assignment wins, which is what the cascade would do.
     */
    private function token(string $selector, string $property): ?string
    {
        $value = null;

        foreach ($this->blocks($selector) as $block) {
            if (preg_match('~(?:^|;)' . preg_quote($property, '~') . ':\s*([^;}]+)~', $block, $match) === 1) {
                $value = strtolower(trim($match[1]));
            }
        }

        return $value;
    }

    /**
     * Every declaration block belonging to exactly this selector.
     *
     * Matched by walking braces rather than by a regular expression, so a
     * nested block inside an `@media` rule cannot swallow the closing brace
     * and return half the stylesheet.
     *
     * @return list<string>
     */
    private function blocks(string $selector): array
    {
        $blocks = [];
        $offset = 0;
        $needle = $selector . '{';

        while (($start = strpos($this->css, $needle, $offset)) !== false) {
            $offset = $start + strlen($needle);

            // A selector list such as `:root,:host{` is not this selector, and
            // neither is `:root[data-theme=dark]` when looking for `:root`.
            // A space is excluded too: in `.dialog .card{` the `.card` is a
            // descendant of something, not the selector being asked about.
            $before = $start === 0 ? '' : $this->css[$start - 1];
            if ($before !== '' && !in_array($before, ['}', ';', ',', '{'], true)) {
                continue;
            }

            $depth = 1;
            $i = $offset;

            while ($i < strlen($this->css) && $depth > 0) {
                $depth += match ($this->css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
                $i++;
            }

            $blocks[] = substr($this->css, $offset, $i - $offset - 1);
        }

        return $blocks;
    }

    /** The WCAG 2.1 contrast ratio between two opaque colours. */
    private function contrast(string $a, string $b): float
    {
        $first = $this->luminance($a);
        $second = $this->luminance($b);

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** Relative luminance, per the WCAG definition. */
    private function luminance(string $hex): float
    {
        [$r, $g, $b] = $this->channels($hex);

        $linear = static function (float $channel): float {
            return $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }


    /**
     * A hex colour as three 0–1 channels.
     *
     * @return array{float, float, float}
     */
    private function channels(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        self::assertMatchesRegularExpression(
            '~^[0-9a-f]{6}$~',
            $hex,
            'Only opaque hex colours can be checked for contrast.',
        );

        return [
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
