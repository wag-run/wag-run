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
