{{--
    Inputs for a list of form field definitions (as declared on the Form trigger,
    the Manual trigger and the Task node), bound to "{$model}.{name}".
--}}
@foreach ($fields as $field)
    @php($binding = $model.'.'.$field['name'])
    <div wire:key="field-{{ $model }}-{{ $field['name'] }}">
        @switch($field['type'] ?? 'text')
            @case('textarea')
                <flux:textarea wire:model="{{ $binding }}" :label="$field['label'] ?? $field['name']" :required="$field['required'] ?? false" />
                @break
            @case('checkbox')
                <flux:checkbox wire:model="{{ $binding }}" :label="$field['label'] ?? $field['name']" />
                @break
            @case('select')
                <flux:select wire:model="{{ $binding }}" :label="$field['label'] ?? $field['name']" :required="$field['required'] ?? false">
                    <flux:select.option value="">{{ __('common.choose') }}</flux:select.option>
                    @foreach ((array) ($field['options'] ?? []) as $optionValue => $optionLabel)
                        <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                    @endforeach
                </flux:select>
                @break
            @case('file')
                <flux:input type="file" wire:model="{{ $binding }}" :label="$field['label'] ?? $field['name']" :required="$field['required'] ?? false" data-test="file-field" />
                @break
            @default
                <flux:input wire:model="{{ $binding }}" :type="in_array($field['type'] ?? 'text', ['number', 'email', 'date'], true) ? $field['type'] : 'text'"
                    :label="$field['label'] ?? $field['name']" :required="$field['required'] ?? false" />
        @endswitch
    </div>
@endforeach
