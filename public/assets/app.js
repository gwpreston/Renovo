/**
 * Keyboard shortcuts, the quick-add dialog and the top bar's theme toggle.
 *
 * Framework-free, and nothing here is load-bearing: every shortcut is a link
 * or a form that also works by clicking it, so a browser with this file blocked
 * loses convenience and no function. That is the same bargain the rest of the
 * application makes with htmx.
 *
 * Strings come from window.renovoI18n, which the layout fills from the server's
 * own catalogue — see AppExtension::jsTranslations().
 */
(function () {
    'use strict';

    var GO_PREFIX_MS = 1200;

    /* Where `g` then a letter goes. */
    var DESTINATIONS = {
        d: '/',
        s: '/subscriptions',
        c: '/calendar',
        b: '/budgets',
        f: '/forecast',
        t: '/stats',
        a: '/settings/notifications',
    };

    var goPressedAt = 0;

    function isTyping(target) {
        if (!target || !target.tagName) {
            return false;
        }

        if (target.isContentEditable) {
            return true;
        }

        var tag = target.tagName.toLowerCase();

        return tag === 'input' || tag === 'textarea' || tag === 'select';
    }

    function dialog(id) {
        var element = document.getElementById(id);

        return element && typeof element.showModal === 'function' ? element : null;
    }

    function openDialog(id) {
        var element = dialog(id);
        if (!element) {
            return false;
        }

        if (!element.open) {
            element.showModal();
        }

        return true;
    }

    /**
     * The quick-add dialog loads the real subscription form rather than
     * carrying a copy of it: one definition of what a subscription needs, and
     * no chance of the short version drifting from the long one.
     */
    function openQuickAdd() {
        var element = dialog('quick-add');
        if (!element) {
            return false;
        }

        var body = element.querySelector('[data-quick-add-body]');

        if (body && !body.dataset.loaded) {
            body.dataset.loaded = 'true';

            fetch(body.dataset.url, {headers: {'HX-Request': 'true'}})
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    body.innerHTML = html;

                    // Inserted by hand rather than swapped by htmx, so htmx has
                    // not seen the form's own attributes — the price's
                    // conversion note asks the server through them — and the
                    // bundle's enhancements have not seen the form at all. The
                    // first is `process()`; the second listens for this event.
                    if (window.htmx) {
                        window.htmx.process(body);
                    }
                    body.dispatchEvent(new CustomEvent('renovo:content-loaded', {bubbles: true}));

                    var first = body.querySelector('input, select, textarea');
                    if (first) {
                        first.focus();
                    }
                })
                .catch(function () {
                    body.textContent = window.renovoI18n.t('quick_add_failed');
                    body.dataset.loaded = '';
                });
        }

        element.showModal();

        return true;
    }

    /**
     * `/` goes to the page's own search before the shell's.
     *
     * The top bar carries a search on every page, and the subscriptions list
     * carries a finer one that filters the table in place. On that page the
     * page's own is what somebody pressing `/` means, so the one inside <main>
     * wins and the bar's is the fallback everywhere else.
     */
    /*
     * The page's own filter first, then the top bar's — whichever is actually
     * on screen. The top bar's is hidden below 1100px, and focusing a hidden
     * field does nothing, so on a narrow page with no filter of its own `/`
     * goes to the list's search instead of being swallowed.
     */
    function focusSearch() {
        var candidates = document.querySelectorAll('main input[type="search"], input[type="search"]');
        var search = null;

        for (var i = 0; i < candidates.length; i += 1) {
            if (candidates[i].getClientRects().length > 0) {
                search = candidates[i];
                break;
            }
        }

        if (!search) {
            if (document.querySelector('.topbar-search')) {
                window.location.href = '/subscriptions';

                return true;
            }

            return false;
        }

        search.focus();
        search.select();

        return true;
    }

    document.addEventListener('keydown', function (event) {
        if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target)) {
            return;
        }

        var key = event.key;

        /* `g` then a letter: two keystrokes, so that single letters stay free. */
        if (goPressedAt && Date.now() - goPressedAt < GO_PREFIX_MS) {
            goPressedAt = 0;

            if (Object.prototype.hasOwnProperty.call(DESTINATIONS, key)) {
                event.preventDefault();
                window.location.href = DESTINATIONS[key];
            }

            return;
        }

        if (key === 'g') {
            goPressedAt = Date.now();

            return;
        }

        if (key === 'n' && openQuickAdd()) {
            event.preventDefault();
        } else if (key === '/' && focusSearch()) {
            event.preventDefault();
        } else if (key === '?' && openDialog('shortcut-help')) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var opener = event.target.closest ? event.target.closest('[data-opens-dialog]') : null;
        if (opener) {
            /*
             * Only swallow the click if a dialog actually opened. The top bar's
             * quick-add is an <a href="/subscriptions/new">, so a browser with
             * no <dialog> support follows it to the real form rather than
             * having its navigation cancelled by a handler that did nothing.
             */
            var opened = opener.dataset.opensDialog === 'quick-add'
                ? openQuickAdd()
                : openDialog(opener.dataset.opensDialog);

            if (opened) {
                event.preventDefault();
            }

            return;
        }

        var closer = event.target.closest ? event.target.closest('[data-closes-dialog]') : null;
        if (closer) {
            event.preventDefault();
            var element = dialog(closer.dataset.closesDialog);
            if (element && element.open) {
                element.close();
            }
        }
    });
    /*
     * The theme toggle. Without this file it is a plain form posting the
     * opposite of the account's setting, and the server sends the reader back.
     * With it, two things improve.
     *
     * An account on "system" is showing whatever the machine asks for, which
     * the server could not know when it wrote the form, so the form is told
     * the opposite of what is actually on screen before anybody presses it.
     *
     * And htmx posts it in place: the server answers with a `renovo:theme`
     * event naming what it saved, and the root's attribute changes under the
     * reader with nothing reloaded. The icon follows by itself — the
     * stylesheet shows whichever matches the root.
     */
    var darkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function showing() {
        var theme = document.documentElement.dataset.theme;
        if (theme === 'light' || theme === 'dark') {
            return theme;
        }

        return darkQuery && darkQuery.matches ? 'dark' : 'light';
    }

    function readyToggles() {
        var next = showing() === 'dark' ? 'light' : 'dark';
        var forms = document.querySelectorAll('.theme-toggle');

        for (var i = 0; i < forms.length; i += 1) {
            var form = forms[i];
            var label = next === 'dark' ? form.dataset.labelDark : form.dataset.labelLight;
            var input = form.querySelector('input[name="theme"]');
            var button = form.querySelector('button');
            var text = form.querySelector('[data-theme-toggle-label]');

            if (input) {
                input.value = next;
            }
            if (button && label) {
                button.title = label;
            }
            if (text && label) {
                text.textContent = label;
            }
        }
    }

    readyToggles();

    if (darkQuery && darkQuery.addEventListener) {
        darkQuery.addEventListener('change', readyToggles);
    }

    document.body.addEventListener('renovo:theme', function (event) {
        var theme = event.detail && event.detail.theme;
        if (theme === 'light' || theme === 'dark' || theme === 'system') {
            document.documentElement.dataset.theme = theme;
            readyToggles();
        }
    });
}());
