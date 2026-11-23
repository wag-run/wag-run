<?php

use App\Services\ApiTokens;
use App\Services\WorkflowApi;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    /** @var list<string> */
    public array $scopes = [WorkflowApi::SCOPE_WORKFLOWS_READ, WorkflowApi::SCOPE_RUNS_READ];

    public ?string $plainToken = null;

    #[Computed]
    public function tokens()
    {
        return app(ApiTokens::class)->personalTokens(Auth::user());
    }

    #[Computed]
    public function apps()
    {
        return app(ApiTokens::class)->connectedApps(Auth::user());
    }

    public function create(ApiTokens $tokens): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(WorkflowApi::personalTokenScopes()))],
        ]);

        $this->plainToken = $tokens->create(Auth::user(), $this->name, $this->scopes);
        $this->reset('name');
        unset($this->tokens);

        Flux::modal('token-created')->show();
    }

    public function revoke(ApiTokens $tokens, string $id): void
    {
        $tokens->revoke(Auth::user(), $id);
        unset($this->tokens);

        Flux::toast(text: __('settings.api_tokens.token_revoked'));
    }

    public function disconnect(ApiTokens $tokens, string $clientId): void
    {
        $tokens->disconnect(Auth::user(), $clientId);
        unset($this->apps);

        Flux::toast(text: __('settings.api_tokens.app_disconnected'));
    }

    public function render()
    {
        return $this->view()->title(__('settings.api_tokens.title'));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.api_tokens.title') }}</flux:heading>

    <x-pages::settings.layout :heading="__('settings.api_tokens.heading')" :subheading="__('settings.api_tokens.subheading')">
        <div class="space-y-10">
            <div class="space-y-3">
                <flux:input :label="__('settings.api_tokens.api_url')" :value="url('/api/v1')" readonly copyable />
                <flux:input :label="__('settings.api_tokens.mcp_server_url')" :value="url('/mcp')" readonly copyable
                    :description="__('settings.api_tokens.add_this_url_to_an')" />
            </div>

            <form wire:submit="create" class="space-y-4">
                <flux:heading>{{ __('settings.api_tokens.create_token') }}</flux:heading>
                <flux:input wire:model="name" :label="__('common.name')" :placeholder="__('settings.api_tokens.name_placeholder')" required />
                <flux:checkbox.group wire:model="scopes" :label="__('settings.api_tokens.access')">
                    @foreach (WorkflowApi::personalTokenScopes() as $scope => $description)
                        <flux:checkbox :value="$scope" :label="$description" :description="$scope" />
                    @endforeach
                </flux:checkbox.group>
                <flux:text class="text-xs">{{ __('settings.api_tokens.tokens_act_as_you') }}</flux:text>
                <flux:button type="submit" variant="primary" data-test="create-token">{{ __('settings.api_tokens.create_token') }}</flux:button>
            </form>

            <div class="space-y-3">
                <flux:heading>{{ __('settings.api_tokens.your_tokens') }}</flux:heading>
                @forelse ($this->tokens as $token)
                    <div class="flex items-center justify-between gap-4" wire:key="token-{{ $token->id }}">
                        <div>
                            <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $token->name }}</flux:text>
                            <flux:text class="text-xs">
                                {{ implode(', ', $token->scopes) }} · {{ __('settings.api_tokens.created_ago', ['ago' => $token->created_at?->diffForHumans()]) }}
                                @if ($token->expires_at)
                                    · {{ __('settings.api_tokens.expires_on', ['date' => $token->expires_at->toFormattedDateString()]) }}
                                @endif
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="trash" :aria-label="__('common.revoke')"
                            x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('settings.api_tokens.revoke_token'), 'message' => __('settings.api_tokens.anything_using_this_token'), 'confirm' => __('common.revoke')]) }}).then((ok) => ok && $wire.revoke({{ \Illuminate\Support\Js::from($token->id) }}))" />
                    </div>
                @empty
                    <flux:text>{{ __('settings.api_tokens.no_tokens_yet') }}</flux:text>
                @endforelse
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('settings.api_tokens.connected_apps') }}</flux:heading>
                @forelse ($this->apps as $token)
                    <div class="flex items-center justify-between gap-4" wire:key="app-{{ $token->client_id }}" data-test="connected-app">
                        <div>
                            <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $token->client?->name }}</flux:text>
                            <flux:text class="text-xs">{{ __('settings.api_tokens.connected_ago', ['ago' => $token->created_at?->diffForHumans()]) }}</flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="x-mark" :aria-label="__('settings.api_tokens.disconnect')"
                            x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('settings.api_tokens.disconnect_app'), 'message' => __('settings.api_tokens.the_app_loses_access'), 'confirm' => __('settings.api_tokens.disconnect')]) }}).then((ok) => ok && $wire.disconnect({{ \Illuminate\Support\Js::from($token->client_id) }}))" />
                    </div>
                @empty
                    <flux:text>{{ __('settings.api_tokens.no_connected_apps') }}</flux:text>
                @endforelse
            </div>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="token-created" class="md:w-lg" x-on:close="$wire.set('plainToken', null)">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('settings.api_tokens.token_created') }}</flux:heading>
            <flux:text>{{ __('settings.api_tokens.copy_it_now') }}</flux:text>
            <flux:input :value="$plainToken" readonly copyable class="font-mono" data-test="plain-token" />
            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="primary">{{ __('common.done') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
