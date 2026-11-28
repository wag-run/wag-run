<x-layouts::auth :title="__('common.log_in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('auth.login.log_in_to_your_account')" :description="__('auth.login.enter_your_email_and_password')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @php($oidcProviders = \App\Models\Organization::current()?->oidcProviders()->where('enabled', true)->orderBy('name')->get() ?? collect())
        @if ($oidcProviders->isNotEmpty())
            <div class="flex flex-col gap-2">
                @foreach ($oidcProviders as $oidcProvider)
                    <flux:button :href="route('oidc.redirect', $oidcProvider)" icon="finger-print" class="w-full" data-test="oidc-{{ $oidcProvider->slug }}">
                        {{ __('auth.login.sign_in_with_name', ['name' => $oidcProvider->name]) }}
                    </flux:button>
                @endforeach
            </div>
            <flux:separator :text="__('auth.login.or')" />
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('common.email_address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('common.password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('common.password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('auth.login.forgot_your_password') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('auth.login.remember_me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('common.log_in') }}
                </flux:button>
            </div>
        </form>

        @if (\App\Models\Organization::current()?->registration_open ?? true)
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('auth.login.don_t_have_an_account') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('auth.login.sign_up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
