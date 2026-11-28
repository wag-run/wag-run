<?php

use App\Engine\Redactor;
use App\Models\Organization;
use App\Support\Audit;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    /**
     * Extra header and field names whose values runs mask.
     *
     * @var list<string>
     */
    public array $sensitiveKeys = [];

    public string $newSensitiveKey = '';

    public function mount(): void
    {
        $this->sensitiveKeys = Organization::current()->sensitive_keys;
    }

    /**
     * Add a name to the list and save it.
     */
    public function addSensitiveKey(): void
    {
        $this->authorize('org-admin');
        $this->newSensitiveKey = trim($this->newSensitiveKey);

        $this->validate(['newSensitiveKey' => ['required', 'string', 'max:100', function (string $attribute, string $value, Closure $fail) {
            if (! Redactor::isValidName($value)) {
                $fail(__('admin.masking.name_invalid'));
            } elseif (in_array(Redactor::normalize($value), array_map(Redactor::normalize(...), $this->sensitiveKeys), true)) {
                $fail(__('admin.masking.name_already_listed'));
            } elseif (count($this->sensitiveKeys) >= 100) {
                $fail(__('admin.masking.too_many_names'));
            }
        }]]);

        $this->save([...$this->sensitiveKeys, $this->newSensitiveKey], __('admin.masking.name_added', ['name' => $this->newSensitiveKey]));
        $this->newSensitiveKey = '';
    }

    public function removeSensitiveKey(int $index): void
    {
        $this->authorize('org-admin');

        $name = $this->sensitiveKeys[$index] ?? null;

        if ($name === null) {
            return;
        }

        unset($this->sensitiveKeys[$index]);
        $this->save(array_values($this->sensitiveKeys), __('admin.masking.name_removed', ['name' => $name]));
    }

    /**
     * @param  list<string>  $names
     */
    protected function save(array $names, string $toast): void
    {
        $organization = Organization::current();
        $organization->fill(['sensitive_keys' => $names]);

        if ($organization->isDirty()) {
            $changed = $organization->changedFields();
            $organization->save();
            Audit::record('organization.settings_updated', $organization, metadata: ['changed' => $changed]);
        }

        $this->sensitiveKeys = $organization->sensitive_keys;
        Flux::toast(variant: 'success', text: $toast);
    }

    public function render()
    {
        return $this->view()->title(__('admin.masking.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('admin.masking.title')" :subheading="__('admin.masking.subheading')">
        <div class="max-w-2xl space-y-8" data-test="masked-values">
            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" data-test="built-in-names">
                <div class="space-y-1">
                    <div class="flex items-center gap-2">
                        <flux:icon.lock-closed variant="micro" class="text-zinc-500 dark:text-zinc-400" />
                        <flux:heading size="sm">{{ __('admin.masking.built_in') }}</flux:heading>
                    </div>
                    <flux:text class="text-xs">{{ __('admin.masking.built_in_help') }}</flux:text>
                </div>
                @foreach (['headers' => Redactor::SENSITIVE_HEADERS, 'field_names' => Redactor::SENSITIVE_FIELDS, 'value_patterns' => Redactor::VALUE_PATTERNS] as $group => $names)
                    <div class="space-y-2">
                        <flux:text class="text-xs font-medium">{{ __('admin.masking.'.$group) }}</flux:text>
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
                    <flux:heading size="sm">{{ __('admin.masking.your_organizations_names') }}</flux:heading>
                    <flux:text class="text-xs">{{ __('admin.masking.your_organizations_names_help') }}</flux:text>
                </div>
                <div class="flex flex-wrap gap-1.5" data-test="organization-names">
                    @forelse ($sensitiveKeys as $index => $sensitiveKey)
                        <flux:badge size="sm" class="font-mono" wire:key="sensitive-key-{{ $index }}-{{ $sensitiveKey }}">
                            {{ $sensitiveKey }}
                            <flux:badge.close wire:click="removeSensitiveKey({{ $index }})" :aria-label="__('admin.masking.remove_name', ['name' => $sensitiveKey])" />
                        </flux:badge>
                    @empty
                        <flux:text class="text-xs">{{ __('admin.masking.no_names_added') }}</flux:text>
                    @endforelse
                </div>
                <form wire:submit="addSensitiveKey" class="flex max-w-md items-start gap-2">
                    <flux:input wire:model="newSensitiveKey" class="font-mono" placeholder="X-Tenant-Key"
                        :aria-label="__('admin.masking.name_to_mask')" data-test="new-sensitive-key" />
                    <flux:button type="submit" data-test="add-sensitive-key">{{ __('admin.masking.add_name') }}</flux:button>
                </form>
                <flux:error name="newSensitiveKey" />
                <flux:text class="text-xs">{{ __('admin.masking.matching_ignores_case') }}</flux:text>
            </div>
        </div>
    </x-pages::admin.layout>
</section>
