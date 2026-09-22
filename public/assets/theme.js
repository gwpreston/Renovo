/**
 * The signed-out theme switch.
 *
 * Only the pages with no account behind them load this — the layout includes
 * it inside `{% if not current_user %}`. A signed-in reader changes their
 * theme under Profile → Appearance, where the choice is saved to the account
 * and rendered by the server on every page they open afterwards.
 *
 * What it writes is a cookie, not local storage, and that is the whole design:
 * the server reads the cookie and renders `data-theme` into the opening <html>
 * tag, so the next page arrives already in the right palette. Local storage
 * would arrive after the parser and repaint a page the reader had already
 * seen — a white flash on every navigation for anyone who asked for dark.
 *
 * The name and the three values it may hold are shared with the server:
 * InstanceContextMiddleware::THEME_COOKIE resolves them through the `Theme`
 * enum, which falls back to "system" for anything it does not recognise.
 *
 * Nothing here is load-bearing. With the file blocked the buttons do nothing,
 * the page is still themed from the cookie the last visit left — or from the
 * browser's own preference — and every form on it still works.
 */
(function () {
    'use strict';

    var COOKIE = 'renovo_theme';
    var CHOICES = ['system', 'light', 'dark'];

    /*
     * A year, because the alternative is a session cookie that forgets the
     * choice the moment the browser closes — which is the same as not offering
     * one. Lax rather than Strict: a preference arriving on a link followed
     * from elsewhere should still be honoured, and there is nothing here worth
     * a CSRF. Not http-only by necessity — the line below is what writes it.
     */
    function remember(choice) {
        var attributes = '; path=/; max-age=31536000; samesite=lax';

        if (window.location.protocol === 'https:') {
            attributes += '; secure';
        }

        document.cookie = COOKIE + '=' + choice + attributes;
    }

    function apply(group, choice) {
        document.documentElement.dataset.theme = choice;

        var buttons = group.querySelectorAll('[data-theme-choice]');

        for (var i = 0; i < buttons.length; i += 1) {
            buttons[i].setAttribute(
                'aria-pressed',
                buttons[i].dataset.themeChoice === choice ? 'true' : 'false'
            );
        }
    }

    var group = document.querySelector('[data-theme-switch]');
    if (!group) {
        return;
    }

    group.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-theme-choice]') : null;
        if (!button) {
            return;
        }

        var choice = button.dataset.themeChoice;
        if (CHOICES.indexOf(choice) === -1) {
            return;
        }

        apply(group, choice);
        remember(choice);
    });
}());
