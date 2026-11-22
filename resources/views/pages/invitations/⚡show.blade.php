<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Invitations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    #[Locked]
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        if (Auth::guest() && $this->invitation?->isPending() && $this->accountExists) {
            session()->put('url.intended', route('invitations.show', $token));
        }
    }

    #[Computed]
    public function invitation(): ?Invitation
    {
        return Invitation::findByToken($this->token)?->load(['workspace', 'organization', 'inviter']);
    }

    #[Computed]
    public function accountExists(): bool
    {
        return $this->invitation && User::query()->where('email', $this->invitation->email)->exists();
    }

    public function accept(Invitations $invitations): void
    {
        abort_unless($this->invitation, 404);

        $invitations->accept($this->invitation, Auth::user());

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function register(Invitations $invitations): void
    {
        abort_unless($this->invitation?->isPending() && ! $this->accountExists && Auth::guest(), 403);

        $this->validate([
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = $invitations->register($this->invitation, $this->name, $this->password);

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return $this->view()->title(__('invitations.show.title'));
    }
}; ?>

<div class="flex flex-col gap-6">
    @php($invitation = $this->invitation)

    @if (! $invitation || ! $invitation->isPending())
        <x-auth-header :title="__('invitations.show.invitation_unavailable')" :description="__('invitations.show.this_invitation_has_expired_was')" />
        <flux:button :href="route('login')" variant="primary" wire:navigate>{{ __('invitations.show.go_to_sign_in') }}</flux:button>
    @else
        <x-auth-header
            :title="__('invitations.show.join_target', ['target' => $invitation->workspace?->name ?? $invitation->organization->name])"
            :description="__('invitations.show.inviter_invited_email', ['inviter' => $invitation->inviter?->name ?? __('common.admin'), 'email' => $invitation->email])"
        />

        <flux:error name="invitation" />

        @auth
            @if (Str::lower(auth()->user()->email) === $invitation->email)
                <flux:button wire:click="accept" variant="primary" data-test="accept-invitation-button">{{ __('common.accept_invitation') }}</flux:button>
            @else
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('invitations.show.you_are_signed_in_as', ['email' => auth()->user()->email, 'invited' => $invitation->email]) }}
                </flux:callout>
            @endif
        @else
            @if ($this->accountExists)
                <flux:text class="text-center">{{ __('invitations.show.you_already_have_an_account') }}</flux:text>
                <flux:button :href="route('login')" variant="primary">{{ __('invitations.show.sign_in') }}</flux:button>
            @else
                <form wire:submit="register" class="flex flex-col gap-6">
                    <flux:input :value="$invitation->email" :label="__('common.email_address')" disabled />
                    <flux:input wire:model="name" :label="__('common.name')" required autofocus autocomplete="name" />
                    <flux:input wire:model="password" type="password" :label="__('common.password')" required autocomplete="new-password" viewable />
                    <flux:input wire:model="password_confirmation" type="password" :label="__('common.confirm_password')" required autocomplete="new-password" viewable />
                    <flux:button type="submit" variant="primary" class="w-full" data-test="register-invitation-button">{{ __('invitations.show.create_account_and_join') }}</flux:button>
                </form>
            @endif
        @endauth
    @endif
</div>
