@php
    /** @var \App\Engine\Definitions\Field $field */
    use App\Engine\Definitions\Field;

    $pickable = $field->templated || $field->expression;
    $description = $field->description
        ?? ($field->expression ? __('workflows.editor.expression') : ($field->templated ? __('workflows.editor.supports_twig_templates') : null));
@endphp

@if ($field->type === Field::BOOLEAN)
    <flux:switch wire:model.live="{{ $model }}" :label="$field->label" :description="$field->description" />
@elseif ($field->type === Field::SELECT)
    <flux:select wire:model.live="{{ $model }}" :label="$field->label" :description="$field->description">
        @foreach ($field->options as $optionValue => $optionLabel)
            <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
        @endforeach
    </flux:select>
@elseif (in_array($field->type, [Field::CREDENTIAL, Field::SECRET, Field::WORKFLOW, Field::USER], true))
    @php
        $options = match ($field->type) {
            Field::CREDENTIAL => collect($choices['credentials'])->filter(fn ($type) => $field->credentialTypes === [] || in_array($type, $field->credentialTypes, true))->mapWithKeys(fn ($type, $key) => [$key => $key])->all(),
            Field::SECRET => $choices['secrets'],
            Field::WORKFLOW => $choices['workflows'],
            Field::USER => $choices['users'],
        };
    @endphp
    <flux:select wire:model.live="{{ $model }}" :label="$field->label" :description="$field->description">
        <flux:select.option value="">{{ __('common.choose') }}</flux:select.option>
        @foreach ($options as $optionValue => $optionLabel)
            <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
        @endforeach
    </flux:select>
    @if ($options === [] && $field->type === Field::CREDENTIAL)
        <flux:text class="text-xs">{{ __('workflows.editor.add_one_under_authentication_first') }}</flux:text>
    @endif
@elseif ($field->type === Field::KEY_VALUE)
    <flux:field>
        <flux:label>{{ $field->label }}</flux:label>
        @if ($field->description)
            <flux:description>{{ $field->description }}</flux:description>
        @endif
        <div class="space-y-2">
            @foreach ((array) $value as $index => $row)
                <div class="flex items-start gap-2" wire:key="{{ $model }}-{{ $index }}">
                    <flux:input size="sm" class="w-28 font-mono" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.key" :placeholder="__('workflows.editor.key')" />
                    <div class="min-w-0 flex-1" x-data="wagInsert">
                        <flux:input size="sm" class="font-mono" x-ref="input" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.value" :placeholder="__('workflows.editor.value')" />
                        @if ($pickable)
                            @include('partials.workflow-picker', ['picker' => $picker, 'expression' => $field->expression])
                        @endif
                    </div>
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeRow('{{ $field->name }}', {{ $index }})" :aria-label="__('common.remove')" />
                </div>
            @endforeach
            <flux:button size="sm" icon="plus" wire:click="addRow('{{ $field->name }}')">{{ __('common.add') }}</flux:button>
        </div>
    </flux:field>
@elseif (in_array($field->type, [Field::LIST, Field::SCHEMA], true))
    <flux:field>
        <flux:label>{{ $field->label }}</flux:label>
        @if ($field->description)
            <flux:description>{{ $field->description }}</flux:description>
        @endif
        <div class="space-y-3">
            @foreach ((array) $value as $index => $row)
                <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="{{ $model }}-{{ $index }}">
                    @foreach ($field->fields as $sub)
                        @if ($sub->type === Field::KEY_VALUE)
                            <flux:textarea size="sm" rows="2" class="font-mono" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.{{ $sub->name }}"
                                :label="$sub->label" :description="__('workflows.editor.one_per_line_as_value')" />
                        @elseif ($sub->type === Field::BOOLEAN)
                            <flux:switch wire:model.live="{{ $model }}.{{ $index }}.{{ $sub->name }}" :label="$sub->label" />
                        @elseif ($sub->type === Field::SELECT)
                            <flux:select size="sm" wire:model.live="{{ $model }}.{{ $index }}.{{ $sub->name }}" :label="$sub->label">
                                @foreach ($sub->options as $optionValue => $optionLabel)
                                    <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:input size="sm" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.{{ $sub->name }}" :label="$sub->label" />
                        @endif
                    @endforeach
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeRow('{{ $field->name }}', {{ $index }})">{{ __('common.remove') }}</flux:button>
                </div>
            @endforeach
            <flux:button size="sm" icon="plus" wire:click="addRow('{{ $field->name }}')">{{ __('common.add') }}</flux:button>
        </div>
    </flux:field>
@else
    <div x-data="wagInsert">
        @if ($field->type === Field::TEXTAREA)
            <flux:textarea x-ref="input" rows="4" @class(['font-mono' => $pickable]) wire:model.live.debounce.600ms="{{ $model }}" :label="$field->label" :description="$description" />
        @else
            <flux:input x-ref="input" @class(['font-mono' => $pickable]) :type="$field->type === Field::NUMBER && ! $field->templated ? 'number' : 'text'"
                wire:model.live.debounce.600ms="{{ $model }}" :label="$field->label" :description="$description" />
        @endif
        @if ($pickable)
            @include('partials.workflow-picker', ['picker' => $picker, 'expression' => $field->expression])
        @endif
    </div>
@endif
