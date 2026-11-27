@php
    /**
     * The input for a data store value of a type, as text the store converts.
     *
     * @var \App\Enums\ValueType $type
     * @var string $model
     * @var string $label
     */
    use App\Enums\ValueType;
@endphp

@if ($type === ValueType::Boolean)
    <flux:select wire:model="{{ $model }}" :label="$label">
        <flux:select.option value="false">false</flux:select.option>
        <flux:select.option value="true">true</flux:select.option>
    </flux:select>
@elseif ($type === ValueType::Number)
    <flux:input wire:model="{{ $model }}" :label="$label" class="font-mono" inputmode="decimal" placeholder="0" />
@elseif ($type === ValueType::DateTime)
    <flux:input wire:model="{{ $model }}" :label="$label" class="font-mono" placeholder="2026-01-31T09:00:00Z" :description="__('workspaces.data_store.datetime_help')" />
@elseif ($type === ValueType::String)
    <flux:textarea wire:model="{{ $model }}" :label="$label" rows="3" />
@else
    <flux:textarea wire:model="{{ $model }}" :label="$label" class="font-mono" rows="5" :placeholder="$type === ValueType::Object ? '{}' : '[]'" :description="__('workspaces.data_store.json_help')" />
@endif
