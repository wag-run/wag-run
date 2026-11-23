import AlpineFlow from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(AlpineFlow);
});

/**
 * Inserts a data reference at the cursor of the field marked x-ref="input".
 * It goes in as if typed, through the browser's insertText command, so the
 * field's own undo and redo (Cmd/Ctrl+Z) include it, and the input event
 * that command fires tells Livewire the value changed. Where the command is
 * unavailable, the text is set directly, which undo does not see.
 */
const insertsText = {
    field() {
        const root = this.$refs.input;

        return root?.matches('input, textarea') ? root : root?.querySelector('input, textarea');
    },

    insert(text, start = null, end = null) {
        const field = this.field();

        if (! field) {
            return;
        }

        start ??= field.selectionStart ?? field.value.length;
        end ??= field.selectionEnd ?? field.value.length;

        field.focus();
        field.setSelectionRange(start, end);

        if (document.execCommand?.('insertText', false, text)) {
            return;
        }

        field.setRangeText(text, start, end, 'end');
        field.dispatchEvent(new Event('input', { bubbles: true }));
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagInsert', () => ({ ...insertsText }));
});

/**
 * Swaps each {{ }} placeholder in JSON text for a stand-in that parses: a
 * placeholder inside a string becomes plain text, one in place of a value
 * becomes a string. restore() puts the placeholders back into text made
 * from the masked JSON.
 */
function maskPlaceholders(text) {
    const placeholders = [];
    let masked = '';
    let inString = false;

    for (let i = 0; i < text.length; i++) {
        const char = text[i];

        if (char === '{' && text[i + 1] === '{') {
            const close = text.indexOf('}}', i + 2);

            if (close !== -1) {
                const id = placeholders.push(text.slice(i, close + 2)) - 1;
                masked += inString ? `__wagp${id}__` : `"__wagb${id}__"`;
                i = close + 1;

                continue;
            }
        }

        if (inString && char === '\\') {
            masked += char + (text[i + 1] ?? '');
            i++;

            continue;
        }

        if (char === '"') {
            inString = ! inString;
        }

        masked += char;
    }

    return {
        masked,
        restore: (json) => json
            .replace(/"__wagb(\d+)__"/g, (match, id) => placeholders[id])
            .replace(/__wagp(\d+)__/g, (match, id) => placeholders[id]),
    };
}

/**
 * A textarea for JSON (partials/json-editor): it checks the JSON as it
 * changes, keeps the line's indentation on Enter (one level more after an
 * opening bracket) and formats it on request. Formatting goes in as if
 * typed, so undo restores the text from before.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagJsonEditor', () => ({
        ...insertsText,
        state: 'empty',
        error: '',

        init() {
            this.$nextTick(() => this.check());
        },

        parse() {
            const { masked, restore } = maskPlaceholders(this.field()?.value ?? '');

            return { value: JSON.parse(masked), restore };
        },

        check() {
            if ((this.field()?.value ?? '').trim() === '') {
                this.state = 'empty';

                return;
            }

            try {
                this.parse();
                this.state = 'valid';
            } catch (error) {
                this.state = 'invalid';
                this.error = error.message;
            }
        },

        format() {
            const field = this.field();

            try {
                const { value, restore } = this.parse();
                const formatted = restore(JSON.stringify(value, null, 2));

                if (formatted !== field.value) {
                    this.insert(formatted, 0, field.value.length);
                }

                this.check();
            } catch (error) {
                this.state = 'invalid';
                this.error = error.message;
            }
        },

        newLine(event) {
            const field = this.field();

            if (event.target !== field || event.shiftKey || event.isComposing) {
                return;
            }

            const before = field.value.slice(0, field.selectionStart);
            const indent = before.slice(before.lastIndexOf('\n') + 1).match(/^\s*/)[0];
            const opens = /[[{]\s*$/.test(before);

            event.preventDefault();
            this.insert('\n' + indent + (opens ? '  ' : ''));
        },
    }));
});

/**
 * WireFlow reports refused connections as a "flux-toast" event; show them
 * with Flux's toast.
 */
window.addEventListener('flux-toast', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;

    window.Flux?.toast({ variant: detail?.variant ?? 'warning', text: detail?.text ?? '' });
});

/**
 * Workflow editor shortcuts: undo and redo (Cmd/Ctrl+Z, Cmd/Ctrl+Shift+Z or
 * Ctrl+Y), and copy, cut and paste of the selected nodes (Cmd/Ctrl+C, X, V).
 * The server keeps the history and the clipboard, so they never run while
 * the user is typing in a field or a dialog is open.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagEditorShortcuts', (canEdit, deleteLabels = {}) => ({
        /**
         * Ask before deleting nodes, however the deletion started: the
         * Delete key, a context menu or the settings panel.
         */
        confirmNodeDelete(count) {
            return this.$confirm({
                title: count === 1 ? deleteLabels.one : deleteLabels.many.replace(':count', count),
                message: deleteLabels.message,
                confirm: deleteLabels.confirm,
            });
        },

        canvas() {
            const container = this.$root.querySelector('.flow-container');

            return container ? window.Alpine.$data(container) : null;
        },

        selectedNodeIds() {
            return [...(this.canvas()?.selectedNodes ?? [])];
        },

        isTyping(target) {
            return target?.closest?.('input, textarea, select, [contenteditable]:not([contenteditable="false"])') !== null;
        },

        onKeydown(event) {
            if (! (event.metaKey || event.ctrlKey) || event.altKey || this.isTyping(event.target) || document.querySelector('dialog[open]')) {
                return;
            }

            const key = event.key.toLowerCase();
            const selected = this.selectedNodeIds();

            if (key === 'c' && selected.length && ! window.getSelection()?.toString()) {
                event.preventDefault();
                this.$wire.copyNodes(selected);

                return;
            }

            if (! canEdit) {
                return;
            }

            if (key === 'z' && ! event.shiftKey) {
                event.preventDefault();
                this.$wire.undo();
            } else if ((key === 'z' && event.shiftKey) || (key === 'y' && ! event.metaKey)) {
                event.preventDefault();
                this.$wire.redo();
            } else if (key === 'x' && selected.length) {
                event.preventDefault();
                this.$wire.cutNodes(selected);
            } else if (key === 'v') {
                event.preventDefault();
                this.$wire.pasteNodes();
            }
        },
    }));
});

/**
 * Whether the workflow editor shows its settings panel (the workflow's
 * settings, or the selected node's). The choice is remembered in this
 * browser. Opening a node's settings (or the workflow's, from the canvas
 * menu) while the panel is hidden shows it only until the next click on
 * the canvas; using the panel's own button keeps the choice.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('editorLayout', {
        settings: localStorage.getItem('wag.settingsPanel') !== 'hidden',
        settingsForNode: false,

        toggleSettings() {
            this.settings = ! this.settings;
            this.settingsForNode = false;
            localStorage.setItem('wag.settingsPanel', this.settings ? 'shown' : 'hidden');
        },

        showSettingsForNode() {
            if (! this.settings) {
                this.settings = true;
                this.settingsForNode = true;
            }
        },

        nodeDeselected() {
            if (this.settingsForNode) {
                this.settings = false;
                this.settingsForNode = false;
            }
        },
    });
});

/**
 * A delete button that sits on each selected connection in the workflow
 * editor, following it as the canvas pans, zooms or nodes move.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagEdgeTrash', () => ({
        buttons: [],
        effect: null,

        init() {
            const canvas = window.Alpine.$data(this.$el.closest('.flow-container'));

            this.effect = window.Alpine.effect(() => {
                const ids = [...(canvas.selectedEdges ?? [])];
                const { x, y, zoom } = canvas.viewport;

                for (const id of ids) {
                    const edge = canvas.edges.find((candidate) => candidate.id === id);

                    for (const node of canvas.nodes.filter((candidate) => candidate.id === edge?.source || candidate.id === edge?.target)) {
                        void node.position.x;
                        void node.position.y;
                    }
                }

                requestAnimationFrame(() => this.place(canvas, ids, x, y, zoom));
            });
        },

        destroy() {
            if (this.effect) {
                window.Alpine.release(this.effect);
            }
        },

        place(canvas, ids, x, y, zoom) {
            this.buttons = ids.flatMap((id) => {
                const path = canvas.getEdgePathElement?.(id);
                const length = path?.getTotalLength?.();

                if (! length) {
                    return [];
                }

                const point = path.getPointAtLength(length / 2);

                return [{ id, left: x + point.x * zoom, top: y + point.y * zoom }];
            });
        },

        remove(id) {
            window.Alpine.$data(this.$el.closest('.flow-container')).removeEdges([id]);
        },
    }));
});

/**
 * $confirm({ title, message, confirm, danger }) opens the app's confirmation
 * modal (partials/confirm-dialog) and resolves to true when the person
 * confirms, false when they cancel or close it. Use it instead of
 * wire:confirm, which shows the browser's native dialog:
 *
 *     x-on:click="$confirm({ title: '…', message: '…' }).then((ok) => ok && $wire.delete(id))"
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('confirmDialog', {
        title: '',
        message: '',
        confirmLabel: '',
        danger: true,
        resolver: null,

        ask({ title = '', message = '', confirm = '', danger = true } = {}) {
            this.settle(false);
            Object.assign(this, { title, message, confirmLabel: confirm, danger });

            return new Promise((resolve) => {
                this.resolver = resolve;
                window.Flux.modal('confirm-dialog').show();
            });
        },

        settle(answer) {
            const resolve = this.resolver;
            this.resolver = null;
            resolve?.(answer);
        },
    });

    window.Alpine.magic('confirm', () => (options) => window.Alpine.store('confirmDialog').ask(options));
});
