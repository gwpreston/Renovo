/**
 * The subscriptions list's bulk selection.
 *
 * The bulk bar is drawn open by the server, so a browser with no script can
 * still tick rows and apply an action. With script it stays out of the way
 * until something is selected, says how many, and a header checkbox selects
 * the whole page. The list is replaced by htmx on every filter, sort and page,
 * so this listens on the document rather than binding to rows that are about
 * to be swapped away.
 */

const BAR = '[data-bulk-bar]';
const ROW = 'input[data-bulk-select]';
const ALL = 'input[data-select-all]';

function update(list) {
    const bar = list.querySelector(BAR);
    if (bar === null) {
        return;
    }

    const rows = [...list.querySelectorAll(ROW)];
    const chosen = rows.filter((row) => row.checked).length;

    bar.hidden = chosen === 0;

    const count = bar.querySelector('[data-bulk-count]');
    if (count !== null && chosen > 0) {
        count.textContent = (count.dataset.countLabel ?? '%d').replace('%d', String(chosen));
    }

    const all = list.querySelector(ALL);
    if (all !== null) {
        all.checked = chosen > 0 && chosen === rows.length;
        all.indeterminate = chosen > 0 && chosen < rows.length;
    }
}

/**
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function enhanceSubscriptionLists(root = document) {
    const lists = root instanceof Element && root.matches('#subscription-list')
        ? [root]
        : [...root.querySelectorAll('#subscription-list')];

    lists.forEach((list) => {
        list.querySelectorAll(ALL).forEach((all) => {
            all.hidden = false;
        });
        update(list);
    });
}

document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) {
        return;
    }

    const list = target.closest('#subscription-list');
    if (list === null) {
        return;
    }

    if (target.matches(ALL)) {
        list.querySelectorAll(ROW).forEach((row) => {
            row.checked = target.checked;
        });
    }

    if (target.matches(ALL) || target.matches(ROW)) {
        update(list);
    }
});
