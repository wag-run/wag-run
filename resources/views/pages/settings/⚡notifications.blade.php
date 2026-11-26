<?php

use App\Enums\NotificationKind;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    /** @var array<string, array{database: bool, mail: bool}> */
    public array $preferences = [];

    public function mount(): void
    {
        foreach (NotificationKind::cases() as $kind) {
            $this->preferences[$kind->value] = [
                'database' => Auth::user()->wantsNotification($kind, 'database'),
                'mail' => Auth::user()->wantsNotification($kind, 'mail'),
            ];
        }
    }

    public function save(): void
    {
        $this->validate([
            'preferences.*.database' => ['boolean'],
            'preferences.*.mail' => ['boolean'],
        ]);

        $saved = [];

        foreach (NotificationKind::cases() as $kind) {
            $saved[$kind->value] = [
                'database' => (bool) ($this->preferences[$kind->value]['database'] ?? true),
                'mail' => (bool) ($this->preferences[$kind->value]['mail'] ?? true),
            ];
        }

        Auth::user()->forceFill(['notification_preferences' => $saved])->save();

        Flux::toast(variant: 'success', text: __('settings.notifications.preferences_saved'));
    }

    public function render()
    {
        return $this->view()->title(__('settings.notifications.title'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.notifications.title') }}</flux:heading>

    <x-pages::settings.layout :heading="__('settings.layout.notifications')" :subheading="__('settings.notifications.choose_how_you_want_to')">
        <form wire:submit="save" class="space-y-6">
            @foreach (App\Enums\NotificationKind::cases() as $kind)
                <flux:fieldset>
                    <flux:legend>{{ $kind->label() }}</flux:legend>
                    <flux:description>{{ $kind->description() }}</flux:description>

                    <div class="mt-3 flex gap-6">
                        <flux:checkbox wire:model="preferences.{{ $kind->value }}.database" :label="__('settings.notifications.in_app')" />
                        <flux:checkbox wire:model="preferences.{{ $kind->value }}.mail" :label="__('settings.notifications.email')" />
                    </div>
                </flux:fieldset>
            @endforeach

            <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
