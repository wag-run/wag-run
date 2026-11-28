<x-layouts::auth :title="__('common.log_in')">
    <div class="flex flex-col gap-6">
        @if (! $email)
            <x-auth-header :title="__('auth.login.log_in_to_your_account')" :description="__('auth.login.enter_your_email_to_continue')" />

            <!-- Session Status -->
            <x-auth-session-status class="text-center" :status="session('status')" />

            <form method="POST" action="{{ route('login.email') }}" class="flex flex-col gap-6">
                @csrf

                <flux:input
                    name="email"
                    :label="__('common.email_address')"
                    :value="old('email')"
                    type="email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="email@example.com"
                />

                <flux:button variant="primary" type="submit" class="w-full" data-test="continue-button">
                    {{ __('common.continue') }}
                </flux:button>
            </form>

            @if (! config('app.self_hosted') || ! \App\Models\Organization::query()->exists())
                <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                    <span>{{ __('auth.login.don_t_have_an_account') }}</span>
                    <flux:link :href="route('register')" wire:navigate>{{ __('auth.login.sign_up') }}</flux:link>
                </div>
            @endif
        @else
            <x-auth-header :title="__('auth.login.log_in_to_your_account')" :description="$organizations->isNotEmpty() ? __('auth.login.choose_an_organization') : __('auth.login.choose_how_to_sign_in')" />

            <!-- Session Status -->
            <x-auth-session-status class="text-center" :status="session('status')" />

            <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-800">
                <span class="truncate" data-test="sign-in-email">{{ $email }}</span>
                <form method="POST" action="{{ route('login.email.destroy') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="shrink-0 cursor-pointer text-zinc-600 underline underline-offset-4 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white" data-test="not-you">
                        {{ __('auth.login.not_you') }}
                    </button>
                </form>
            </div>

            <flux:error name="email" />

            @if ($organizations->isNotEmpty())
                <div class="flex flex-col gap-2" data-test="organization-picker">
                    @foreach ($organizations as $organization)
                        <form method="POST" action="{{ route('login.organization') }}">
                            @csrf
                            <input type="hidden" name="organization" value="{{ $organization->id }}" />
                            <flux:button type="submit" class="w-full" icon="building-office" data-test="choose-{{ $organization->id }}">{{ $organization->name }}</flux:button>
                        </form>
                    @endforeach
                </div>
            @else
            @if ($providers->isEmpty() && ! $passwordAllowed)
                <flux:callout variant="secondary" icon="information-circle" :heading="__('auth.login.there_s_no_way_for')" />
            @endif

            @if ($providers->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($providers as $provider)
                        <flux:button :href="route('oidc.redirect', $provider)" :variant="$claimingProvider?->is($provider) ? 'primary' : 'outline'" icon="finger-print" class="w-full" data-test="oidc-{{ $provider->slug }}">
                            {{ __('auth.login.sign_in_with_name', ['name' => $provider->name]) }}
                        </flux:button>
                    @endforeach
                </div>
                @if ($passwordAllowed)
                    <flux:separator :text="__('auth.login.or')" />
                @endif
            @endif

            @if ($passwordAllowed)
            <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
                @csrf

                <!-- Lets password managers save and fill the right email with the password -->
                <input type="email" name="email" value="{{ $email }}" autocomplete="username" readonly tabindex="-1" aria-hidden="true" class="sr-only" />

                <div class="relative">
                    <flux:input
                        name="password"
                        :label="__('common.password')"
                        type="password"
                        required
                        :autofocus="! $claimingProvider"
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

                <flux:checkbox name="remember" :label="__('auth.login.remember_me')" :checked="old('remember')" />

                <flux:button :variant="$claimingProvider ? 'filled' : 'primary'" type="submit" class="w-full" data-test="login-button">
                    {{ __('common.log_in') }}
                </flux:button>
            </form>
            @endif
            @endif
        @endif
    </div>
</x-layouts::auth>
