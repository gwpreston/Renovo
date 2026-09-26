/**
 * Keyboard shortcuts, the dialogs that load a form, and the top bar's theme toggle.
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
     * A dialog that loads the real form rather than carrying a copy of it: one
     * definition of what a subscription or a budget needs, and no chance of
     * the short version drifting from the long one.
     *
     * The body names the page to load in `data-url`; an opener may name
     * another in `data-dialog-url` — one dialog serves New and every Edit —
     * and the body is reloaded whenever the page asked for changes. Its
     * `data-dialog-title`, if any, becomes the dialog's heading.
     */
    function openRemote(id, url, title) {
        var element = dialog(id);
        if (!element) {
            return false;
        }

        var heading = element.querySelector('[data-dialog-title]');
        if (heading && title) {
            heading.textContent = title;
        }

        var body = element.querySelector('[data-dialog-body]');
        var target = url || (body && body.dataset.url);

        if (body && target && body.dataset.loaded !== target) {
            body.dataset.loaded = target;
            body.innerHTML = '';
            var loading = document.createElement('p');
            loading.className = 'muted';
            loading.textContent = window.renovoI18n.t('dialog_loading');
            body.appendChild(loading);

            fetch(target, {headers: {'HX-Request': 'true'}})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.text();
                })
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

                    var first = body.querySelector('input:not([type="hidden"]), select, textarea');
                    if (first) {
                        first.focus();
                    }
                })
                .catch(function () {
                    body.textContent = window.renovoI18n.t('quick_add_failed');
                    body.dataset.loaded = '';
                });
        }

        if (!element.open) {
            element.showModal();
        }

        return true;
    }

    function openQuickAdd() {
        return openRemote('quick-add');
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
            var opened = opener.dataset.opensDialog === 'quick-add' || opener.dataset.dialogUrl
                ? openRemote(opener.dataset.opensDialog, opener.dataset.dialogUrl, opener.dataset.dialogTitle)
                : openDialog(opener.dataset.opensDialog);

            if (opened) {
                event.preventDefault();
            }

            return;
        }

        /*
         * A closer that is a link — the budget form's Cancel — is also how the
         * full page leaves, so it is only swallowed when there is a dialog
         * open for it to close.
         */
        var closer = event.target.closest ? event.target.closest('[data-closes-dialog]') : null;
        if (closer) {
            var element = dialog(closer.dataset.closesDialog);
            if (element && element.open) {
                event.preventDefault();
                element.close();
            } else if (closer.tagName !== 'A') {
                event.preventDefault();
            }
        }
    });
    /*
     * A range input that names an <output> shows its value there as it moves:
     * the budget form's "Warn me at". The output is rendered with the initial
     * value, so without this file it is only stale while dragging.
     */
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input || !input.dataset || !input.dataset.rangeOutput) {
            return;
        }

        var output = document.getElementById(input.dataset.rangeOutput);
        if (output) {
            output.textContent = window.renovoI18n.t('percent', {percent: input.value});
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

    /*
     * The profile's appearance form, saved on change: the server names what
     * it stored and the root takes it, so the page is wearing the choice the
     * moment it is made. Only values of the expected shape are copied — the
     * attributes are selectors for the whole stylesheet. The sentence goes to
     * the form's status line, which is a live region, so a screen reader
     * hears that it saved without the focus moving.
     */
    document.body.addEventListener('renovo:preferences', function (event) {
        var detail = event.detail || {};
        var root = document.documentElement;

        if (detail.theme === 'light' || detail.theme === 'dark' || detail.theme === 'system') {
            root.dataset.theme = detail.theme;
            readyToggles();
        }
        if (typeof detail.palette === 'string' && /^[a-z]+$/.test(detail.palette)) {
            root.dataset.palette = detail.palette;
        }
        if (detail.density === 'comfortable' || detail.density === 'compact') {
            root.dataset.density = detail.density;
        }

        var status = document.querySelector('[data-preferences-status]');
        if (status && typeof detail.message === 'string') {
            status.textContent = detail.message;
        }
    });
}());
