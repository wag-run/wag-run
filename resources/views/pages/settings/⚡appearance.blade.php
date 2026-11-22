<?php

use Livewire\Component;

new class extends Component {
    //

    public function render()
    {
        return $this->view()->title(__('settings.appearance.title'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.appearance.appearance_settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('common.appearance')" :subheading="__('settings.appearance.update_the_appearance_settings_for')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('settings.appearance.light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('settings.appearance.dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('common.system') }}</flux:radio>
        </flux:radio.group>
    </x-pages::settings.layout>
</section>
