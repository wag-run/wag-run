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
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::auth')] #[Title('Invitation')] class extends Component {
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
}; ?>

<div class="flex flex-col gap-6">
    @php($invitation = $this->invitation)

    @if (! $invitation || ! $invitation->isPending())
        <x-auth-header :title="__('Invitation unavailable')" :description="__('This invitation has expired, was revoked, or was already used. Ask an admin to send a new one.')" />
        <flux:button :href="route('login')" variant="primary" wire:navigate>{{ __('Go to sign in') }}</flux:button>
    @else
        <x-auth-header
            :title="__('Join :target', ['target' => $invitation->workspace?->name ?? $invitation->organization->name])"
            :description="__(':inviter invited :email.', ['inviter' => $invitation->inviter?->name ?? __('An admin'), 'email' => $invitation->email])"
        />

        <flux:error name="invitation" />

        @auth
            @if (Str::lower(auth()->user()->email) === $invitation->email)
                <flux:button wire:click="accept" variant="primary" data-test="accept-invitation-button">{{ __('Accept invitation') }}</flux:button>
            @else
                <flux:callout variant="warning" icon="exclamation-triangle">
                    {{ __('You are signed in as :email. Sign out and open this link again to accept as :invited.', ['email' => auth()->user()->email, 'invited' => $invitation->email]) }}
                </flux:callout>
            @endif
        @else
            @if ($this->accountExists)
                <flux:text class="text-center">{{ __('You already have an account. Sign in to accept.') }}</flux:text>
                <flux:button :href="route('login')" variant="primary">{{ __('Sign in') }}</flux:button>
            @else
                <form wire:submit="register" class="flex flex-col gap-6">
                    <flux:input :value="$invitation->email" :label="__('Email address')" disabled />
                    <flux:input wire:model="name" :label="__('Name')" required autofocus autocomplete="name" />
                    <flux:input wire:model="password" type="password" :label="__('Password')" required autocomplete="new-password" viewable />
                    <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm password')" required autocomplete="new-password" viewable />
                    <flux:button type="submit" variant="primary" class="w-full" data-test="register-invitation-button">{{ __('Create account and join') }}</flux:button>
                </form>
            @endif
        @endauth
    @endif
</div>
