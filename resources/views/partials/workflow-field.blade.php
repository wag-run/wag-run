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
            Field::CREDENTIAL => collect($choices['credentials'])->filter(fn (array $credential) => $field->credentialTypes === [] || in_array($credential['type'], $field->credentialTypes, true))->map(fn (array $credential) => $credential['label'])->all(),
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
@elseif ($field->type === Field::CRON)
    @php
        $expression = trim((string) $value);
        $timezone = trim((string) (($values ?? [])['timezone'] ?? '')) ?: 'UTC';
        $cronDescription = \App\Support\CronDescription::describe($expression);
        $knownTimezone = \App\Support\TimeZones::isKnown($timezone);
        $nextRuns = \App\Support\CronDescription::nextRuns($expression, $timezone);
        $presets = [
            '*/15 * * * *' => __('nodes.cron.preset_every_15_minutes'),
            '0 * * * *' => __('nodes.cron.preset_every_hour'),
            '0 9 * * *' => __('nodes.cron.preset_every_day_at_9'),
            '0 9 * * 1-5' => __('nodes.cron.preset_weekdays_at_9'),
            '0 9 * * 1' => __('nodes.cron.preset_mondays_at_9'),
            '0 0 1 * *' => __('nodes.cron.preset_first_of_the_month'),
        ];
    @endphp
    <div class="space-y-2" data-test="cron-field">
        <flux:input class="font-mono" wire:model.live.debounce.400ms="{{ $model }}" :label="$field->label" :description="$field->description" placeholder="0 9 * * 1-5" />
        <flux:dropdown position="bottom" align="start">
            <flux:button size="xs" variant="subtle" icon="clock" data-test="cron-presets">{{ __('nodes.cron.presets') }}</flux:button>
            <flux:menu>
                @foreach ($presets as $presetExpression => $presetLabel)
                    <flux:menu.item data-cron="{{ $presetExpression }}" x-on:click="$wire.set(@js($model), $el.dataset.cron)">
                        <span>{{ $presetLabel }}</span>
                        <span class="ms-auto ps-3 font-mono text-[10px] text-zinc-400">{{ $presetExpression }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
        @if ($expression !== '')
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs dark:border-zinc-700 dark:bg-zinc-800/50" data-test="cron-preview">
                @if (! \App\Support\CronDescription::isValid($expression))
                    <p class="text-red-600 dark:text-red-400">{{ __('nodes.cron.not_a_valid_cron_expression') }}</p>
                @else
                    @if ($cronDescription)
                        <p class="font-medium text-zinc-800 dark:text-zinc-100" data-test="cron-description">{{ $cronDescription }}</p>
                    @endif
                    @if (! $knownTimezone)
                        <p class="text-red-600 dark:text-red-400">{{ __('nodes.cron.unknown_time_zone', ['timezone' => $timezone]) }}</p>
                    @elseif ($nextRuns === [])
                        <p class="text-red-600 dark:text-red-400">{{ __('nodes.cron.this_schedule_never_runs') }}</p>
                    @else
                        <p @class(['text-zinc-500', 'mt-2' => $cronDescription])>{{ __('nodes.cron.next_runs_in_timezone', ['timezone' => $timezone]) }}</p>
                        <ul class="mt-1 space-y-0.5 font-mono text-zinc-600 dark:text-zinc-300">
                            @foreach ($nextRuns as $run)
                                <li>{{ $run->locale(app()->getLocale())->isoFormat('ddd D MMM YYYY, HH:mm') }}</li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>
        @endif
    </div>
@elseif ($field->type === Field::TIMEZONE)
    @php($currentTimezone = trim((string) $value))
    <flux:select wire:model.live="{{ $model }}" :label="$field->label" :description="$field->description" data-test="timezone-field">
        @if ($currentTimezone !== '' && ! \App\Support\TimeZones::isKnown($currentTimezone))
            <flux:select.option :value="$currentTimezone">{{ __('workflows.editor.unknown_time_zone_option', ['timezone' => $currentTimezone]) }}</flux:select.option>
        @endif
        <flux:select.option value="UTC">UTC</flux:select.option>
        @foreach (\App\Support\TimeZones::grouped() as $region => $zones)
            <optgroup label="{{ $region }}">
                @foreach ($zones as $zone => $place)
                    <flux:select.option :value="$zone">{{ $place }}</flux:select.option>
                @endforeach
            </optgroup>
        @endforeach
    </flux:select>
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
@elseif ($field->isJson($values ?? []))
    @include('partials.json-editor', ['model' => $model, 'label' => $field->label, 'description' => $description, 'picker' => $pickable ? $picker : null])
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
