{{-- What a field with placeholders would become, shown while it has focus.
     Goes inside an x-data="wagInsert" or "wagJsonEditor" element, which
     fills it in. Livewire leaves it alone, so updates do not reset it. --}}
<div wire:ignore>
    <div x-ref="preview" x-show="focused && (preview || previewLoading)" style="display: none"
        class="mt-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800/50" data-test="expression-preview">
        <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
            <span class="font-medium">{{ __('workflows.editor.preview') }}</span>
            <span class="font-mono text-[10px] text-zinc-400" x-show="preview?.type" x-text="preview?.type"></span>
            <flux:icon.loading class="ms-auto size-3" x-show="previewLoading" />
        </div>
        <template x-if="preview">
            <div>
                <pre x-show="preview.status === 'value'" x-text="preview.value"
                    class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-words font-mono text-zinc-800 dark:text-zinc-100"></pre>
                <p x-show="preview.status === 'empty'" class="mt-1 italic text-zinc-400">{{ __('workflows.editor.preview_empty') }}</p>
                <p x-show="preview.status === 'error'" x-text="preview.value" class="mt-1 text-red-600 dark:text-red-400"></p>
                <p x-text="preview.source" class="mt-1 text-[10px] text-zinc-400"></p>
            </div>
        </template>
    </div>
</div>
