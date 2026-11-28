<x-layouts::auth :title="__('auth.register.register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('auth.register.create_an_account')" :description="__('auth.register.enter_your_details_below_to')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('common.name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('auth.register.full_name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('common.email_address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Organization -->
            <flux:input
                name="organization"
                :label="__('auth.register.organization_name')"
                :value="old('organization')"
                type="text"
                maxlength="100"
                autocomplete="organization"
                :placeholder="__('auth.register.organization_name_placeholder')"
                :description:trailing="__('auth.register.you_can_rename_it_later')"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('common.password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('common.password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('common.confirm_password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('common.confirm_password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('auth.register.create_account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('auth.register.already_have_an_account') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('common.log_in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
