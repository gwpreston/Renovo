/**
 * The signed-out forms' four enhancements: show/hide on a password, the
 * strength meter, the "these don't match" line, and the six code boxes.
 *
 * Every one is optional. The server renders each form complete — a password
 * field, the rule stated in words, a single code input — and the server's own
 * validation is the answer; this only makes the same form easier to fill in.
 * With the script blocked nothing is lost but the hints.
 */

/**
 * Show/hide. The button is rendered hidden and revealed here, so a control
 * that would do nothing is never offered; its name says what pressing it will
 * do, and `aria-pressed` says what it has done.
 */
function enhancePasswordToggles(root) {
    root.querySelectorAll('[data-password-field]').forEach((field) => {
        const input = field.querySelector('input');
        const button = field.querySelector('.password-toggle');

        if (!input || !button || button.dataset.bound) {
            return;
        }

        button.dataset.bound = '1';
        button.hidden = false;

        button.addEventListener('click', () => {
            const showing = input.type === 'text';

            input.type = showing ? 'password' : 'text';
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
            button.setAttribute('aria-label', showing ? field.dataset.labelShow : field.dataset.labelHide);
            input.focus();
        });

        // A form submitted with the password showing leaves it showing in the
        // browser's history; hide it again on the way out.
        input.form?.addEventListener('submit', () => {
            input.type = 'password';
        });
    });
}

/**
 * How many of the four bars a password lights.
 *
 * Below the server's minimum it is 0 — "Too short", the one verdict the
 * server shares. At the minimum it is 1, and length past `long` and a mix of
 * cases and of digits or symbols each add one. The server accepts every score
 * from 1 up alike: this is a hint about the password, not a rule.
 */
export function passwordScore(password, min, long) {
    const length = [...password].length;

    if (length < min) {
        return 0;
    }

    let score = 1;

    if (length >= long) {
        score++;
    }

    if (/\p{Lu}/u.test(password) && /\p{Ll}/u.test(password)) {
        score++;
    }

    if (/[\p{N}\p{P}\p{S}]/u.test(password)) {
        score++;
    }

    return Math.min(score, 4);
}

function enhanceMeters(root) {
    root.querySelectorAll('[data-password-meter]').forEach((meter) => {
        const input = document.getElementById(meter.dataset.for);
        const label = meter.querySelector('[data-meter-label]');

        if (!input || meter.dataset.bound) {
            return;
        }

        meter.dataset.bound = '1';

        const min = Number(meter.dataset.min);
        const long = Number(meter.dataset.long);
        let labels = [];

        try {
            labels = JSON.parse(meter.dataset.labels);
        } catch {
            labels = [];
        }

        const update = () => {
            if (input.value === '') {
                delete meter.dataset.score;
                label.textContent = '';

                return;
            }

            const score = passwordScore(input.value, min, long);

            meter.dataset.score = String(score);
            label.textContent = labels[score] || '';
        };

        input.addEventListener('input', update);
        update();
    });
}

/**
 * The confirmation field: a mismatch is said in words, under the field, and
 * as `aria-invalid` — not only as a red edge. Checked once the second field
 * has been left or is as long as the first, so it does not complain after the
 * first keystroke.
 */
function enhanceConfirmations(root) {
    root.querySelectorAll('[data-confirms]').forEach((confirm) => {
        const original = document.getElementById(confirm.dataset.confirms);
        const message = document.getElementById(confirm.id + '-error');

        if (!original || !message || confirm.dataset.bound) {
            return;
        }

        confirm.dataset.bound = '1';
        let touched = false;

        const check = () => {
            if (!touched && confirm.value.length < original.value.length) {
                return;
            }

            const mismatch = confirm.value !== '' && confirm.value !== original.value;

            if (mismatch) {
                confirm.setAttribute('aria-invalid', 'true');
                message.textContent = message.dataset.message;
                message.hidden = false;
            } else {
                confirm.removeAttribute('aria-invalid');
                message.hidden = true;
            }
        };

        confirm.addEventListener('input', check);
        original.addEventListener('input', () => confirm.value !== '' && check());
        confirm.addEventListener('blur', () => {
            touched = true;
            check();
        });
    });
}

/**
 * The six boxes: a picture of the one real input, which lies transparently
 * over them. Typing, pasting and autofill all go into the input; this copies
 * the digits into the boxes and marks the one the next digit will fill.
 */
function enhanceCodeEntries(root) {
    root.querySelectorAll('[data-code-entry]').forEach((entry) => {
        const input = entry.querySelector('input');
        const boxes = [...entry.querySelectorAll('.code-box')];

        if (!input || boxes.length === 0 || entry.dataset.bound) {
            return;
        }

        entry.dataset.bound = '1';
        entry.classList.add('is-enhanced');

        const draw = () => {
            // Digits only, and six of them: a pasted "123 456" is a code.
            const digits = input.value.replace(/\D/g, '').slice(0, boxes.length);

            if (digits !== input.value) {
                input.value = digits;
            }

            boxes.forEach((box, index) => {
                box.textContent = digits[index] || '';
                box.classList.toggle('is-next', index === Math.min(digits.length, boxes.length - 1));
            });
        };

        input.addEventListener('input', draw);
        input.addEventListener('focus', () => entry.classList.add('is-focused'));
        input.addEventListener('blur', () => entry.classList.remove('is-focused'));

        if (document.activeElement === input) {
            entry.classList.add('is-focused');
        }

        draw();
    });
}

export function enhanceAuthForms(root = document) {
    enhancePasswordToggles(root);
    enhanceMeters(root);
    enhanceConfirmations(root);
    enhanceCodeEntries(root);
}
