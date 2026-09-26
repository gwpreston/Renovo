/**
 * The subscription form's one rule that is easier to show than to explain:
 * "Only me" and a cost split cannot both be chosen, because a split is always
 * seen by the people in it.
 *
 * The server refuses the pair whichever way it arrives; this only greys out
 * the choice the other one rules out, as it is made, so nobody fills in a
 * split and then learns on saving that it could not be kept private. The
 * server draws the same state on first render, so with no script the form
 * still offers only what it can save.
 */

const FORM = 'form[data-subscription-form]';

function sync(form) {
    const onlyMe = form.querySelector('input[name="visibility"][value="payer"]');
    const splits = [...form.querySelectorAll('input[name="split_mode"]')];
    if (onlyMe === null || splits.length === 0) {
        return;
    }

    const splitChosen = splits.some((input) => input.checked && input.value !== 'none');

    // `data-locked`: a split this member cannot change, so "only me" stays
    // closed whatever happens on the form.
    onlyMe.disabled = onlyMe.dataset.locked !== undefined || splitChosen;

    splits.forEach((input) => {
        if (input.value !== 'none') {
            input.disabled = onlyMe.checked;
        }
    });
}

/**
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function enhanceSubscriptionForms(root = document) {
    root.querySelectorAll(FORM).forEach((form) => {
        if (form.dataset.enhanced === '1') {
            return;
        }
        form.dataset.enhanced = '1';

        form.addEventListener('change', (event) => {
            const target = event.target;
            if (target instanceof HTMLInputElement && (target.name === 'visibility' || target.name === 'split_mode')) {
                sync(form);
            }
        });

        sync(form);
    });
}
