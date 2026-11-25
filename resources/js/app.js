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
 *
 * A reference or function picked while the cursor is already inside a
 * {{ }} placeholder goes in bare, so it becomes part of that expression.
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

    insideExpression(field) {
        const before = field.value.slice(0, field.selectionStart ?? field.value.length);
        const open = before.lastIndexOf('{{');

        return open !== -1 && before.indexOf('}}', open) === -1;
    },

    insertReference(path) {
        const field = this.field();

        if (field) {
            this.insert(this.insideExpression(field) ? path : `{{ ${path} }}`);
        }
    },

    /**
     * Inserts a call to a helper function. Selected text becomes its
     * argument; otherwise the cursor lands between the parentheses.
     */
    insertFunction(name, takesArguments = true) {
        const field = this.field();

        if (! field) {
            return;
        }

        const selected = field.value.slice(field.selectionStart, field.selectionEnd);
        const inside = this.insideExpression(field);
        const call = `${name}(${selected})`;

        this.insert(inside ? call : `{{ ${call} }}`);

        if (takesArguments && selected === '') {
            const cursor = field.selectionEnd - (inside ? 1 : 4);
            field.setSelectionRange(cursor, cursor);
        }
    },
};

const escapeHtml = (text) => text.replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

/**
 * The position of the "}}" that closes a placeholder opened before $from,
 * minding quotes and braces the way the server does
 * (ExpressionTemplateEngine::closingBraces), or -1.
 */
function closingBraces(text, from) {
    let quote = null;
    let depth = 0;

    for (let i = from; i < text.length; i++) {
        const char = text[i];

        if (quote !== null) {
            if (char === '\\') {
                i++;
            } else if (char === quote) {
                quote = null;
            }

            continue;
        }

        if (char === '"' || char === "'") {
            quote = char;
        } else if (char === '{') {
            depth++;
        } else if (char === '}') {
            if (depth === 0 && text[i + 1] === '}') {
                return i;
            }

            depth = Math.max(0, depth - 1);
        }
    }

    return -1;
}

/**
 * The inside of a placeholder as HTML: strings and function names get
 * their own colour.
 */
function highlightExpression(expression) {
    return expression.replace(
        /("(?:\\.|[^"\\])*"?|'(?:\\.|[^'\\])*'?)|([A-Za-z_][A-Za-z0-9_]*)(?=\s*\()|([^"'A-Za-z_]+|[A-Za-z_][A-Za-z0-9_]*)/g,
        (match, string, call, other) => {
            if (string) {
                return `<span class="wag-x-string">${escapeHtml(string)}</span>`;
            }

            if (call) {
                return `<span class="wag-x-function">${escapeHtml(call)}</span>`;
            }

            return escapeHtml(other);
        },
    );
}

/**
 * Text with each {{ }} placeholder marked, as HTML. A placeholder that is
 * never closed is marked as a problem up to the end.
 */
function highlightTemplate(text) {
    let html = '';
    let offset = 0;
    let start;

    while ((start = text.indexOf('{{', offset)) !== -1) {
        html += escapeHtml(text.slice(offset, start));
        const close = closingBraces(text, start + 2);

        if (close === -1) {
            return html + `<span class="wag-x-unclosed">${escapeHtml(text.slice(start))}</span>`;
        }

        html += `<span class="wag-x"><span class="wag-x-brace">{{</span>${highlightExpression(text.slice(start + 2, close))}<span class="wag-x-brace">}}</span></span>`;
        offset = close + 2;
    }

    return html + escapeHtml(text.slice(offset));
}

/**
 * A field that takes placeholders: the text is drawn again, with the
 * placeholders coloured, in a layer (x-ref="highlight") laid exactly over
 * the field, whose own text turns transparent once the layer is ready. If
 * the field has a preview (x-ref="preview"), what it would become is
 * worked out on the server as the person types, while the field has focus.
 */
const expressionField = {
    ...insertsText,
    preview: null,
    previewLoading: false,
    focused: false,
    previewRequest: 0,
    previewTimer: null,

    setUpExpressionField() {
        const field = this.field();
        const layer = this.$refs.highlight;

        if (! field || ! layer) {
            return;
        }

        const refresh = () => this.drawHighlight(field, layer);
        field.addEventListener('input', () => {
            refresh();
            this.schedulePreview(field);
        });
        field.addEventListener('scroll', () => this.followScroll(field, layer));
        new ResizeObserver(() => this.placeHighlight(field, layer)).observe(field);
        document.fonts?.ready.then(() => this.placeHighlight(field, layer));

        const model = [...field.attributes].find((attribute) => attribute.name.startsWith('wire:model'))?.value;

        if (model && this.$wire) {
            this.$wire.$watch(model, () => this.$nextTick(() => {
                refresh();
                this.schedulePreview(field);
            }));
        }

        this.$root.addEventListener('focusin', () => {
            this.focused = true;
            this.schedulePreview(field);
        });
        this.$root.addEventListener('focusout', (event) => {
            this.focused = this.$root.contains(event.relatedTarget);
        });

        this.placeHighlight(field, layer);
        layer.dataset.ready = '';
    },

    placeHighlight(field, layer) {
        const style = getComputedStyle(field);
        const fieldBox = field.getBoundingClientRect();
        const rootBox = this.$root.getBoundingClientRect();
        const isInput = field.tagName === 'INPUT';
        const borders = parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth);
        const scrollbar = isInput ? 0 : Math.max(0, field.offsetWidth - field.clientWidth - borders);

        for (const property of ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'wordSpacing', 'textIndent', 'textTransform', 'tabSize',
            'paddingTop', 'paddingLeft', 'paddingBottom', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth', 'boxSizing', 'textAlign']) {
            layer.style[property] = style[property];
        }

        Object.assign(layer.style, {
            top: `${fieldBox.top - rootBox.top}px`,
            left: `${fieldBox.left - rootBox.left}px`,
            width: `${field.offsetWidth}px`,
            height: `${field.offsetHeight}px`,
            paddingRight: `${parseFloat(style.paddingRight) + scrollbar}px`,
            whiteSpace: isInput ? 'pre' : 'pre-wrap',
            overflowWrap: isInput ? 'normal' : 'break-word',
            lineHeight: isInput ? `${field.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom)}px` : style.lineHeight,
        });

        this.drawHighlight(field, layer);
    },

    drawHighlight(field, layer) {
        const value = field.value;
        layer.innerHTML = highlightTemplate(value) + (field.tagName === 'TEXTAREA' && value.endsWith('\n') ? ' ' : '');
        this.followScroll(field, layer);
    },

    followScroll(field, layer) {
        layer.scrollTop = field.scrollTop;
        layer.scrollLeft = field.scrollLeft;
    },

    schedulePreview(field) {
        if (! this.$refs.preview || ! this.$wire) {
            return;
        }

        clearTimeout(this.previewTimer);

        if (! field.value.includes('{{')) {
            this.preview = null;
            this.previewLoading = false;

            return;
        }

        this.previewLoading = true;
        this.previewTimer = setTimeout(async () => {
            const request = ++this.previewRequest;
            const preview = await this.$wire.previewTemplate(field.value);

            if (request === this.previewRequest) {
                this.preview = preview;
                this.previewLoading = false;
            }
        }, 350);
    },
};

/**
 * Whether no trigger leads to a node on the workflow canvas, so a run can
 * never reach it. Read by the canvas node template, which redraws as
 * connections are added and removed.
 */
window.wagWontRun = (id, nodes, edges) => {
    const reached = new Set(nodes.filter((node) => node.data?.isTrigger).map((node) => node.id));
    const queue = [...reached];

    while (queue.length) {
        const current = queue.pop();

        for (const edge of edges) {
            if (edge.source === current && ! reached.has(edge.target)) {
                reached.add(edge.target);
                queue.push(edge.target);
            }
        }
    }

    return ! reached.has(id);
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('wagInsert', () => ({
        ...expressionField,

        init() {
            this.$nextTick(() => this.setUpExpressionField());
        },
    }));
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
        ...expressionField,
        state: 'empty',
        error: '',

        init() {
            this.$nextTick(() => {
                this.check();
                this.setUpExpressionField();
            });
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
