{{-- A textarea for JSON: it checks the JSON as you type, keeps the
     indentation on Enter and formats it on request. {{ }} placeholders
     count as values, so a templated body can still be checked.

     $model: the wire:model path; $live: send changes as you type;
     $label, $description; $picker: the data picker's groups, if any.
     The status starts hidden, so a page without the script never claims
     the JSON is valid. --}}
@php
    $live ??= true;
    $rows ??= 8;
@endphp
<div x-data="wagJsonEditor" x-on:input.debounce.300ms="check()" x-on:keydown.enter="newLine($event)" data-test="json-editor">
    @if ($live)
        <flux:textarea x-ref="input" :rows="$rows" class="font-mono text-xs" spellcheck="false" autocomplete="off"
            wire:model.live.debounce.600ms="{{ $model }}" :label="$label" :description="$description ?? null" />
    @else
        <flux:textarea x-ref="input" :rows="$rows" class="font-mono text-xs" spellcheck="false" autocomplete="off"
            wire:model="{{ $model }}" :label="$label" :description="$description ?? null" />
    @endif
    <div class="mt-1 flex flex-wrap items-center gap-2">
        <flux:button size="xs" variant="subtle" icon="code-bracket" x-on:click="format()" data-test="json-format">{{ __('workflows.editor.format_json') }}</flux:button>
        @if (isset($picker))
            @include('partials.workflow-picker', ['picker' => $picker, 'inRow' => true])
        @endif
        <span class="text-xs text-green-600 dark:text-green-400" style="display: none" x-show="state === 'valid'" data-test="json-valid">{{ __('workflows.editor.valid_json') }}</span>
        <span class="text-xs text-red-600 dark:text-red-400" style="display: none" x-show="state === 'invalid'" data-test="json-invalid"
            data-template="{{ __('workflows.editor.not_valid_json', ['error' => '__error__']) }}" x-text="$el.dataset.template.replace('__error__', error)"></span>
    </div>
</div>
