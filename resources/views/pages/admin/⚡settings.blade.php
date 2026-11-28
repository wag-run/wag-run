<?php

use App\Engine\Redactor;
use App\Models\Organization;
use App\Support\Audit;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    public bool $keepForever = false;

    public ?int $defaultRetentionDays = null;

    public bool $allowAccountDeletion = true;

    public int|string $maxChainDepth = 10;

    public int|string $maxRunsPerMinute = 60;

    /**
     * Extra header and field names whose values runs mask.
     *
     * @var list<string>
     */
    public array $sensitiveKeys = [];

    public string $newSensitiveKey = '';

    public function mount(): void
    {
        $organization = Organization::current();

        $this->name = $organization->name;
        $this->keepForever = $organization->default_retention_days === null;
        $this->defaultRetentionDays = $organization->default_retention_days ?? 30;
        $this->allowAccountDeletion = $organization->allow_account_deletion;
        $this->maxChainDepth = $organization->max_chain_depth;
        $this->maxRunsPerMinute = $organization->max_runs_per_minute;
        $this->sensitiveKeys = $organization->sensitive_keys;
    }

    /**
     * Add a name to the list. It is saved with the other settings.
     */
    public function addSensitiveKey(): void
    {
        $this->newSensitiveKey = trim($this->newSensitiveKey);

        $this->validate(['newSensitiveKey' => ['required', 'string', 'max:100', function (string $attribute, string $value, Closure $fail) {
            if (! Redactor::isValidName($value)) {
                $fail(__('admin.settings.name_invalid'));
            } elseif (in_array(Redactor::normalize($value), array_map(Redactor::normalize(...), $this->sensitiveKeys), true)) {
                $fail(__('admin.settings.name_already_listed'));
            }
        }]]);

        $this->sensitiveKeys[] = $this->newSensitiveKey;
        $this->newSensitiveKey = '';
    }

    public function removeSensitiveKey(int $index): void
    {
        unset($this->sensitiveKeys[$index]);
        $this->sensitiveKeys = array_values($this->sensitiveKeys);
    }

    public function save(): void
    {
        $this->authorize('org-admin');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'defaultRetentionDays' => [$this->keepForever ? 'nullable' : 'required', 'integer', 'min:1', 'max:3650'],
            'maxChainDepth' => ['required', 'integer', 'min:1', 'max:100'],
            'maxRunsPerMinute' => ['required', 'integer', 'min:1', 'max:10000'],
            'sensitiveKeys' => ['array', 'max:100'],
            'sensitiveKeys.*' => ['string', 'max:100', fn (string $attribute, mixed $value, Closure $fail) => Redactor::isValidName((string) $value) ? null : $fail(__('admin.settings.name_invalid'))],
        ]);

        $organization = Organization::current();
        $organization->fill([
            'name' => $this->name,
            'default_retention_days' => $this->keepForever ? null : $this->defaultRetentionDays,
            'allow_account_deletion' => $this->allowAccountDeletion,
            'max_chain_depth' => (int) $this->maxChainDepth,
            'max_runs_per_minute' => (int) $this->maxRunsPerMinute,
            'sensitive_keys' => array_values($this->sensitiveKeys),
        ]);

        if ($organization->isDirty()) {
            $changed = $organization->changedFields();
            $organization->save();
            Audit::record('organization.settings_updated', $organization, metadata: ['changed' => $changed]);
        }

        Flux::toast(variant: 'success', text: __('admin.settings.organization_settings_saved'));
    }

    public function render()
    {
        return $this->view()->title(__('admin.settings.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('admin.settings.title')" :subheading="__('admin.settings.organization_wide_settings_workspaces_and')">
        <form wire:submit="save" class="max-w-2xl space-y-10">
            <flux:input wire:model="name" :label="__('admin.settings.organization_name')" required />

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.accounts') }}</flux:heading>
                <flux:switch wire:model="allowAccountDeletion" :label="__('admin.settings.let_people_delete_their_accounts')"
                    :description="__('admin.settings.when_off_the_delete_account')" data-test="allow-account-deletion" />
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.default_execution_log_retention') }}</flux:heading>
                <flux:switch wire:model.live="keepForever" :label="__('admin.settings.keep_execution_logs_forever')" />
                @unless ($keepForever)
                    <flux:input wire:model="defaultRetentionDays" type="number" min="1" max="3650" :label="__('common.keep_logs_for_days')" class="max-w-48" />
                @endunless
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.workflow_chains') }}</flux:heading>
                <flux:input wire:model="maxChainDepth" type="number" min="1" max="100" :label="__('admin.settings.maximum_chain_depth')"
                    :description="__('admin.settings.how_many_runs_deep_a')" class="max-w-48" data-test="max-chain-depth" />
                <flux:input wire:model="maxRunsPerMinute" type="number" min="1" max="10000" :label="__('admin.settings.maximum_runs_per_minute')"
                    :description="__('admin.settings.how_many_runs_one_workflow')" class="max-w-48" data-test="max-runs-per-minute" />
            </div>

            <div class="space-y-6" data-test="masked-values">
                <div>
                    <flux:heading>{{ __('admin.settings.masked_values') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('admin.settings.masked_values_help') }}</flux:text>
                </div>

                <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="built-in-names">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:icon.lock-closed variant="micro" class="text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="sm">{{ __('admin.settings.built_in') }}</flux:heading>
                        <flux:text class="text-xs">{{ __('admin.settings.built_in_help') }}</flux:text>
                    </div>
                    @foreach (['headers' => Redactor::SENSITIVE_HEADERS, 'field_names' => Redactor::SENSITIVE_FIELDS, 'value_patterns' => Redactor::VALUE_PATTERNS] as $group => $names)
                        <div class="space-y-2">
                            <flux:text class="text-xs font-medium">{{ __('admin.settings.'.$group) }}</flux:text>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($names as $builtIn)
                                    <flux:badge size="sm" class="font-mono">{{ $builtIn }}</flux:badge>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-3">
                    <div>
                        <flux:heading size="sm">{{ __('admin.settings.your_organizations_names') }}</flux:heading>
                        <flux:text class="text-xs">{{ __('admin.settings.your_organizations_names_help') }}</flux:text>
                    </div>
                    <div class="flex flex-wrap gap-1.5" data-test="organization-names">
                        @forelse ($sensitiveKeys as $index => $sensitiveKey)
                            <flux:badge size="sm" class="font-mono" wire:key="sensitive-key-{{ $index }}-{{ $sensitiveKey }}">
                                {{ $sensitiveKey }}
                                <flux:badge.close wire:click="removeSensitiveKey({{ $index }})" :aria-label="__('admin.settings.remove_name', ['name' => $sensitiveKey])" />
                            </flux:badge>
                        @empty
                            <flux:text class="text-xs">{{ __('admin.settings.no_names_added') }}</flux:text>
                        @endforelse
                    </div>
                    <div class="flex max-w-md items-start gap-2">
                        <flux:input wire:model="newSensitiveKey" wire:keydown.enter.prevent="addSensitiveKey" class="font-mono" placeholder="X-Tenant-Key"
                            :aria-label="__('admin.settings.name_to_mask')" data-test="new-sensitive-key" />
                        <flux:button wire:click="addSensitiveKey" data-test="add-sensitive-key">{{ __('admin.settings.add_name') }}</flux:button>
                    </div>
                    <flux:error name="newSensitiveKey" />
                    <flux:error name="sensitiveKeys.*" />
                    <flux:text class="text-xs">{{ __('admin.settings.matching_ignores_case') }}</flux:text>
                    <flux:text class="text-xs">{{ __('admin.settings.turn_a_name_off_on_a_node') }}</flux:text>
                </div>
            </div>

            <flux:button type="submit" variant="primary">{{ __('common.save_settings') }}</flux:button>
        </form>
    </x-pages::admin.layout>
</section>
