{{-- Passport's consent screen, shown when an app such as an MCP client asks to act for the signed-in person. --}}
<x-layouts::auth :title="__('api.authorize.title', ['app' => $client->name])">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('api.authorize.title', ['app' => $client->name])"
            :description="__('api.authorize.signed_in_as', ['email' => $user->email])"
        />

        <div class="space-y-2">
            <flux:text>{{ __('api.authorize.it_will_be_able_to') }}</flux:text>
            <ul class="list-disc space-y-1 ps-5 text-sm text-zinc-600 dark:text-zinc-300">
                @forelse ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @empty
                    <li>{{ __('api.scopes.mcp_use') }}</li>
                @endforelse
            </ul>
        </div>

        @php($redirectUri = request()->query('redirect_uri', $client->redirect_uris[0] ?? null))
        @if ($redirectUri)
            <flux:text class="break-all text-xs">{{ __('api.authorize.you_will_be_sent_to', ['uri' => $redirectUri]) }}</flux:text>
        @endif

        <div class="flex gap-3">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" class="w-full">{{ __('common.cancel') }}</flux:button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <flux:button type="submit" variant="primary" class="w-full" data-test="authorize-app">{{ __('api.authorize.authorize') }}</flux:button>
            </form>
        </div>
    </div>
</x-layouts::auth>
