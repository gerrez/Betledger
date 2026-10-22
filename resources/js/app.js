/**
 * Confirmation before an action: a button with `data-confirm="Message"` (plus optional
 * `data-confirm-title`, `data-confirm-button` and `data-confirm-danger`) opens the shared
 * confirm dialog (`<x-confirm-dialog>`) instead of running its click. Confirming replays
 * the click, so `wire:click` and other handlers on the button run as usual.
 */
document.addEventListener(
    'click',
    (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;

        if (trigger === null) {
            return;
        }

        if (trigger._confirmed) {
            delete trigger._confirmed;

            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        window.dispatchEvent(
            new CustomEvent('confirm-action', {
                detail: {
                    title: trigger.dataset.confirmTitle ?? 'Are you sure?',
                    message: trigger.dataset.confirm,
                    button: trigger.dataset.confirmButton ?? 'Confirm',
                    danger: trigger.hasAttribute('data-confirm-danger'),
                    proceed: () => {
                        trigger._confirmed = true;
                        trigger.click();
                    },
                },
            }),
        );
    },
    true,
);

document.addEventListener('alpine:init', () => {
    window.Alpine.data('confirmDialog', () => ({
        title: '',
        message: '',
        button: '',
        danger: false,
        proceed: null,

        open(detail) {
            this.title = detail.title;
            this.message = detail.message;
            this.button = detail.button;
            this.danger = detail.danger;
            this.proceed = detail.proceed;

            this.$flux.modal('confirm-action').show();
            this.$nextTick(() => this.$root.querySelector('[data-confirm-cancel]').focus());
        },

        confirm() {
            const proceed = this.proceed;

            this.proceed = null;
            this.$flux.modal('confirm-action').close();
            proceed?.();
        },
    }));

    /**
     * The `<x-select>` dropdown: a select-only combobox (WAI-ARIA pattern) whose listbox
     * opens in the top layer (popover), so scrolling filter rows and modals don't clip it.
     * Focus stays on the button; the highlighted option is announced with
     * aria-activedescendant. Bound to Livewire with `wire:model` through `x-modelable`.
     */
    window.Alpine.data('selectMenu', () => ({
        value: null,
        isOpen: false,
        activeId: null,
        optionsChanged: 0,
        typed: '',
        typedAt: 0,

        init() {
            new MutationObserver(() => this.optionsChanged++).observe(this.listbox(), {
                childList: true,
                subtree: true,
                characterData: true,
            });
        },

        // Looked up rather than x-ref'd: init() and the first x-text run before child refs exist.
        button() {
            return this.$root.querySelector('[role="combobox"]');
        },

        listbox() {
            return this.$root.querySelector('[role="listbox"]');
        },

        options() {
            return Array.from(this.listbox().querySelectorAll('[role="option"]'));
        },

        labelOf(option) {
            return option.textContent.trim();
        },

        isSelected(option) {
            return option.dataset.value === String(this.value ?? '');
        },

        selectedLabel() {
            this.optionsChanged;

            const selected = this.options().find((option) => this.isSelected(option));

            return selected === undefined ? '' : this.labelOf(selected);
        },

        toggle() {
            this.isOpen ? this.close() : this.open();
        },

        open() {
            if (this.isOpen || this.button().disabled) {
                return;
            }

            const options = this.options();

            this.isOpen = true;
            this.listbox().showPopover();
            this.position();
            this.activate(options.find((option) => this.isSelected(option)) ?? options[0]);
        },

        close() {
            if (!this.isOpen) {
                return;
            }

            this.isOpen = false;
            this.activeId = null;
            this.listbox().hidePopover();
        },

        choose(option) {
            if (option !== undefined && !this.isSelected(option)) {
                this.value = option.dataset.value;
            }

            this.close();
            this.button().focus();
        },

        activate(option) {
            if (option === undefined) {
                return;
            }

            this.activeId = option.id;
            option.scrollIntoView({ block: 'nearest' });
        },

        move(step) {
            const options = this.options();
            const current = options.findIndex((option) => option.id === this.activeId);
            const next = Math.min(Math.max(current + step, 0), options.length - 1);

            this.activate(options[next]);
        },

        typeahead(character) {
            const now = Date.now();

            this.typed = now - this.typedAt > 600 ? character : this.typed + character;
            this.typedAt = now;

            const search = this.typed.toLowerCase();
            const options = this.options();
            const current = options.findIndex((option) => option.id === this.activeId);
            const ordered = this.typed.length === 1 ? [...options.slice(current + 1), ...options.slice(0, current + 1)] : options;

            this.activate(ordered.find((option) => this.labelOf(option).toLowerCase().startsWith(search)));
        },

        onKeydown(event) {
            const isCharacter = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;
            const isTyping = Date.now() - this.typedAt < 600;

            if (!this.isOpen) {
                if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    event.preventDefault();
                    this.open();

                    if (event.key === 'Home') {
                        this.activate(this.options()[0]);
                    } else if (event.key === 'End') {
                        this.activate(this.options().at(-1));
                    }
                } else if (isCharacter && event.key !== ' ') {
                    this.open();
                    this.typeahead(event.key);
                }

                return;
            }

            const active = this.options().find((option) => option.id === this.activeId);

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    this.move(1);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    event.altKey ? this.choose(active) : this.move(-1);
                    break;
                case 'PageDown':
                    event.preventDefault();
                    this.move(10);
                    break;
                case 'PageUp':
                    event.preventDefault();
                    this.move(-10);
                    break;
                case 'Home':
                    event.preventDefault();
                    this.activate(this.options()[0]);
                    break;
                case 'End':
                    event.preventDefault();
                    this.activate(this.options().at(-1));
                    break;
                case 'Enter':
                    event.preventDefault();
                    this.choose(active);
                    break;
                case ' ':
                    event.preventDefault();
                    isTyping ? this.typeahead(' ') : this.choose(active);
                    break;
                case 'Escape':
                    // Stop here so an enclosing modal stays open.
                    event.preventDefault();
                    event.stopPropagation();
                    this.close();
                    break;
                case 'Tab':
                    this.close();
                    break;
                default:
                    if (isCharacter) {
                        this.typeahead(event.key);
                    }
            }
        },

        /**
         * Places the listbox under the button, or above it when there is more room there.
         */
        position() {
            if (!this.isOpen) {
                return;
            }

            const gap = 6;
            const margin = 8;
            const button = this.button().getBoundingClientRect();
            const listbox = this.listbox();
            const below = window.innerHeight - button.bottom - gap - margin;
            const above = button.top - gap - margin;

            listbox.style.minWidth = `${button.width}px`;
            listbox.style.maxHeight = `${Math.min(320, Math.max(below, above))}px`;

            const height = listbox.offsetHeight;
            const width = listbox.offsetWidth;
            const top = height <= below || below >= above ? button.bottom + gap : button.top - gap - height;

            listbox.style.top = `${top}px`;
            listbox.style.left = `${Math.max(margin, Math.min(button.left, window.innerWidth - width - margin))}px`;
        },
    }));
});
