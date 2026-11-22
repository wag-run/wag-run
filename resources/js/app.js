import AlpineFlow from '../../vendor/getartisanflow/wireflow/dist/alpineflow.bundle.esm.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(AlpineFlow);
});

/**
 * Inserts a data reference at the cursor of the field marked x-ref="input",
 * then tells Livewire the value changed.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagInsert', () => ({
        insert(text) {
            const root = this.$refs.input;
            const field = root?.matches('input, textarea') ? root : root?.querySelector('input, textarea');

            if (! field) {
                return;
            }

            const start = field.selectionStart ?? field.value.length;
            const end = field.selectionEnd ?? field.value.length;
            field.setRangeText(text, start, end, 'end');
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.focus();
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
    window.Alpine.data('wagEditorShortcuts', (canEdit) => ({
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
