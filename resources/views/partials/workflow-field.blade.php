@php
    /** @var \App\Engine\Definitions\Field $field */
    use App\Engine\Definitions\Field;

    $pickable = $field->templated;
    $description = $field->description
        ?? ($field->templated ? __('workflows.editor.use_placeholders_to_insert_data') : null);
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
    @php
        $registry = app(\App\Credentials\CredentialTypeRegistry::class);
        $oauthType = $field->type === Field::CREDENTIAL && count($field->credentialTypes) === 1 && $registry->has($field->credentialTypes[0])
            ? $registry->get($field->credentialTypes[0])
            : null;
        $oauthType = $oauthType instanceof \App\Credentials\OAuthCredentialType && $oauthType->connectsFromNodes() ? $oauthType : null;
    @endphp
    @if ($oauthType && ! $oauthType->isConfigured())
        <flux:text class="text-xs" data-test="oauth-not-set-up">{{ __('workflows.editor.provider_not_set_up', ['provider' => $oauthType->label(), 'keys' => implode(' and ', $oauthType->environmentKeys())]) }}</flux:text>
    @elseif ($oauthType && ($canConnectAccounts ?? false))
        <flux:button size="sm" icon="link" wire:click="connectAccount('{{ $field->name }}')" data-test="connect-account">
            {{ $options === [] ? __('workflows.editor.connect_provider', ['provider' => $oauthType->label()]) : __('workflows.editor.connect_another_provider_account', ['provider' => $oauthType->label()]) }}
        </flux:button>
    @elseif ($oauthType && $options === [])
        <flux:text class="text-xs">{{ __('workflows.editor.ask_an_admin_to_connect', ['provider' => $oauthType->label()]) }}</flux:text>
    @elseif ($options === [] && $field->type === Field::CREDENTIAL)
        <flux:text class="text-xs">{{ __('workflows.editor.add_one_under_authentication_first') }}</flux:text>
    @endif
@elseif ($field->type === Field::USERS)
    <flux:checkbox.group wire:model.live="{{ $model }}" :label="$field->label" :description="$field->description">
        <div class="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
            @forelse ($choices['users'] as $optionValue => $optionLabel)
                <flux:checkbox :value="(string) $optionValue" :label="$optionLabel" />
            @empty
                <flux:text class="text-xs">{{ __('workflows.editor.no_people_to_choose') }}</flux:text>
            @endforelse
        </div>
    </flux:checkbox.group>
@elseif ($field->type === Field::KEY_VALUE)
    <flux:field>
        <flux:label>{{ $field->label }}</flux:label>
        @if ($field->description)
            <flux:description>{{ $field->description }}</flux:description>
        @endif
        <div class="space-y-2">
            @foreach ((array) $value as $index => $row)
                <div class="flex items-start gap-2" wire:key="{{ $model }}-{{ $index }}">
                    <div class="w-28 shrink-0">
                        <flux:input size="sm" class="font-mono" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.key" :placeholder="__('workflows.editor.key')" />
                    </div>
                    <div class="min-w-0 flex-1" x-data="wagInsert">
                        <flux:input size="sm" class="font-mono" x-ref="input" wire:model.live.debounce.600ms="{{ $model }}.{{ $index }}.value" :placeholder="__('workflows.editor.value')" />
                        @if ($pickable)
                            @include('partials.workflow-picker', ['picker' => $picker])
                        @endif
                    </div>
                    <flux:button size="sm" variant="ghost" icon="x-mark" class="shrink-0" wire:click="removeRow('{{ $field->name }}', {{ $index }})" :aria-label="__('common.remove')" />
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
                        @continue(! $sub->isVisible((array) $row, $field->fields))
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
            @include('partials.workflow-picker', ['picker' => $picker])
        @endif
    </div>
@endif
