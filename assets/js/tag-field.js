/**
 * The tag field, made searchable.
 *
 * An enhancement of the ordinary text input the server renders, never a
 * replacement for it. The input keeps its name, its id and its comma-separated
 * value, so the form posts exactly what it posted before and
 * `TagRepository::resolveOrCreate()` goes on matching an existing name or
 * creating a new one. With this script blocked the field is still a text box
 * you can type "streaming, shared" into, which is the whole reason it is built
 * this way round.
 *
 * Why not a library. A combobox is a listbox, a text input and a set of
 * keyboard rules, and that is roughly what is below; the smallest well-known
 * option for this job brings jQuery with it, for one field on one form, into a
 * bundle that has none. Nothing here is loaded from anywhere — see
 * non-negotiable 8 in CLAUDE.md.
 *
 * Accessibility is the ARIA 1.2 combobox pattern rather than an approximation:
 * the input owns the listbox through `aria-controls`, announces itself with
 * `aria-expanded`, points at the highlighted row with `aria-activedescendant`,
 * and the chips are real buttons that say what removing them does. The
 * suggestion list is never the only way to add a tag — typing a comma still
 * commits one.
 */

const SEPARATOR = ', ';

/**
 * Split a field value into tag names, dropping blanks and duplicates.
 *
 * Case-insensitive on the duplicate test but preserving of what was typed:
 * "Work" and "work" are the same tag to the server, and the first spelling
 * entered is the one kept.
 */
function parse(value) {
    const seen = new Set();
    const names = [];

    for (const raw of String(value).split(',')) {
        const name = raw.trim();
        if (name === '') {
            continue;
        }

        const key = name.toLocaleLowerCase();
        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        names.push(name);
    }

    return names;
}

function enhance(input) {
    /*
     * The suggestions the server rendered. Read from the DOM rather than from
     * a global: the list belongs to this field, and a second tag field on some
     * later page should get its own rather than share one.
     */
    const source = document.getElementById(input.dataset.tagOptions ?? '');
    const available = source
        ? Array.from(source.options, (option) => option.value).filter((value) => value !== '')
        : [];

    let selected = parse(input.value);
    let active = -1;

    // The real input goes on holding the value and the name; it is hidden from
    // view and from assistive technology, because the visible text box below
    // is what a person actually types into and two fields announcing
    // themselves as "Tags" is worse than none.
    input.type = 'hidden';
    input.removeAttribute('placeholder');

    const wrapper = document.createElement('div');
    wrapper.className = 'tag-field';

    const chips = document.createElement('ul');
    chips.className = 'tag-chips plain';

    const box = document.createElement('input');
    box.type = 'text';
    box.className = 'tag-input';
    box.setAttribute('role', 'combobox');
    box.setAttribute('aria-expanded', 'false');
    box.setAttribute('aria-autocomplete', 'list');
    box.autocomplete = 'off';
    box.id = `${input.id}-input`;

    const list = document.createElement('ul');
    list.className = 'tag-suggestions plain';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    list.id = `${input.id}-listbox`;
    box.setAttribute('aria-controls', list.id);

    // The label the server wrote points at the hidden input; move it to the
    // box that now takes the typing.
    const label = input.form?.querySelector(`label[for="${CSS.escape(input.id)}"]`);
    if (label) {
        label.setAttribute('for', box.id);
    }

    /*
     * A live region for the chips. Adding and removing a tag is a change a
     * sighted user sees immediately and a screen-reader user otherwise would
     * not hear at all, because focus never moves.
     */
    const announcer = document.createElement('p');
    announcer.className = 'visually-hidden';
    announcer.setAttribute('aria-live', 'polite');

    input.parentNode.insertBefore(wrapper, input);
    wrapper.append(chips, box, list, announcer);
    wrapper.append(input);

    function commit() {
        input.value = selected.join(SEPARATOR);
    }

    function announce(message) {
        announcer.textContent = message;
    }

    function drawChips() {
        chips.replaceChildren();

        for (const name of selected) {
            const item = document.createElement('li');
            item.className = 'chip chip-static';

            const text = document.createElement('span');
            text.textContent = name;

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-icon';
            remove.textContent = '×';
            // The server rendered the sentence from the catalogue with `%s`
            // where the name goes; only the substitution happens here.
            remove.setAttribute('aria-label', (input.dataset.removeLabel ?? 'Remove %s').replace('%s', name));
            remove.addEventListener('click', () => {
                selected = selected.filter((candidate) => candidate !== name);
                commit();
                drawChips();
                announce(remove.getAttribute('aria-label'));
                box.focus();
            });

            item.append(text, remove);
            chips.append(item);
        }
    }

    function matches() {
        const typed = box.value.trim().toLocaleLowerCase();
        const chosen = new Set(selected.map((name) => name.toLocaleLowerCase()));

        return available
            .filter((name) => !chosen.has(name.toLocaleLowerCase()))
            .filter((name) => typed === '' || name.toLocaleLowerCase().includes(typed))
            .slice(0, 8);
    }

    function close() {
        list.hidden = true;
        list.replaceChildren();
        box.setAttribute('aria-expanded', 'false');
        box.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function highlight(index) {
        const options = Array.from(list.children);
        active = index;

        options.forEach((option, position) => {
            const isActive = position === index;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        if (index >= 0 && options[index]) {
            box.setAttribute('aria-activedescendant', options[index].id);
        } else {
            box.removeAttribute('aria-activedescendant');
        }
    }

    function add(name) {
        const trimmed = name.trim();
        if (trimmed === '') {
            return;
        }

        if (!selected.some((candidate) => candidate.toLocaleLowerCase() === trimmed.toLocaleLowerCase())) {
            selected.push(trimmed);
            commit();
            drawChips();
            announce(trimmed);
        }

        box.value = '';
        close();
    }

    function open() {
        const found = matches();
        list.replaceChildren();

        if (found.length === 0) {
            close();

            return;
        }

        found.forEach((name, index) => {
            const option = document.createElement('li');
            option.id = `${list.id}-${index}`;
            option.className = 'tag-suggestion';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = name;
            // `mousedown`, not `click`: the input's blur would close the list
            // out from under a click before it landed.
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                add(name);
            });
            list.append(option);
        });

        list.hidden = false;
        box.setAttribute('aria-expanded', 'true');
        highlight(-1);
    }

    box.addEventListener('input', () => {
        // A comma is how the plain field separated tags, so it goes on doing so.
        if (box.value.includes(',')) {
            const parts = box.value.split(',');
            box.value = parts.pop() ?? '';
            parts.forEach(add);

            return;
        }

        open();
    });

    box.addEventListener('focus', open);
    box.addEventListener('blur', close);

    box.addEventListener('keydown', (event) => {
        const options = Array.from(list.children);

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (list.hidden) {
                    open();

                    return;
                }
                highlight(active + 1 >= options.length ? 0 : active + 1);
                break;

            case 'ArrowUp':
                event.preventDefault();
                if (!list.hidden) {
                    highlight(active - 1 < 0 ? options.length - 1 : active - 1);
                }
                break;

            case 'Enter':
                // Only swallowed when it is doing something here. Otherwise it
                // submits the form, which is what Enter in a text field does.
                if (!list.hidden && active >= 0) {
                    event.preventDefault();
                    add(options[active].textContent);
                } else if (box.value.trim() !== '') {
                    event.preventDefault();
                    add(box.value);
                }
                break;

            case 'Escape':
                if (!list.hidden) {
                    event.stopPropagation();
                    close();
                }
                break;

            case 'Backspace':
                // Only when there is nothing to delete in the box itself, so
                // backspace never eats a chip while there is still text.
                if (box.value === '' && selected.length > 0) {
                    const removed = selected.pop();
                    commit();
                    drawChips();
                    announce(removed);
                }
                break;

            default:
                break;
        }
    });

    drawChips();
    commit();
    wrapper.classList.add('is-enhanced');
}

/**
 * Enhance every tag field in the given root. Safe to run twice: an input that
 * has already been enhanced is hidden and marked, and is skipped.
 */
export function enhanceTagFields(root = document) {
    for (const input of root.querySelectorAll('input[data-tag-options]')) {
        if (input.type !== 'hidden') {
            enhance(input);
        }
    }
}
