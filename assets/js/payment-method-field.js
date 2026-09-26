/**
 * The subscription form's payment-method select, with its badge kept in step.
 *
 * A native `<option>` cannot hold a picture, so the select stays a plain one —
 * which is what a browser with no script posts, and what the server rendered
 * the badge beneath it from. This only redraws that badge when the choice
 * changes, from the `data-logo` and `data-icon-name` each option carries.
 *
 * The badge is built from elements, never from markup: the method's name is
 * the household's free text and goes in as text.
 */

import { icon } from './icons.js';

const SELECT = 'select[data-payment-method-select]';

function badgeFor(option) {
    const badge = document.createElement('span');
    badge.className = 'payment-badge';

    const logo = option.dataset.logo ?? '';
    if (logo !== '') {
        const image = document.createElement('img');
        image.className = 'payment-badge-logo';
        image.src = logo;
        image.alt = '';
        image.width = 16;
        image.height = 16;
        badge.append(image);
    } else {
        const drawing = icon(option.dataset.iconName || 'payment-card', 'payment-badge-icon');

        if (drawing !== null) {
            badge.append(drawing);
        }
    }

    const name = document.createElement('span');
    name.className = 'payment-badge-name';
    name.textContent = option.textContent.trim();
    badge.append(name);

    return badge;
}

/**
 * @param {ParentNode} [root] Where to look; the whole document by default.
 */
export function enhancePaymentMethodFields(root = document) {
    root.querySelectorAll(SELECT).forEach((select) => {
        if (select.dataset.enhanced === '1') {
            return;
        }
        select.dataset.enhanced = '1';

        const preview = document.getElementById(select.dataset.paymentMethodSelect);
        if (preview === null) {
            return;
        }

        select.addEventListener('change', () => {
            const option = select.selectedOptions[0];
            preview.replaceChildren();

            if (option !== undefined && option.value !== '') {
                preview.append(badgeFor(option));
            }
        });
    });
}
