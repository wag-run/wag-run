<?php

use App\Enums\InstallRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invitations;
use App\Services\UserDirectory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Users')] class extends Component {
    public string $email = '';

    public string $installRole = 'member';

    public ?string $inviteLink = null;

    public ?string $resetLink = null;

    public ?string $resetLinkFor = null;

    #[Computed]
    public function users()
    {
        return Organization::current()->users()->orderBy('name')->get();
    }

    #[Computed]
    public function invitations()
    {
        return Organization::current()->invitations()->pending()->whereNull('workspace_id')->latest()->get();
    }

    public function invite(Invitations $invitations): void
    {
        $this->authorize('install-admin');

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'installRole' => ['required', Rule::enum(InstallRole::class)],
        ]);

        [, $token] = $invitations->invite(Organization::current(), $this->email, Auth::user(), InstallRole::from($this->installRole));

        $this->inviteLink = route('invitations.show', $token);
        $this->reset('email', 'installRole');
        Flux::toast(variant: 'success', text: __('Invitation sent.'));
    }

    public function revoke(int $invitationId, Invitations $invitations): void
    {
        $this->authorize('install-admin');

        $invitations->revoke(Organization::current()->invitations()->findOrFail($invitationId));
        Flux::toast(text: __('Invitation revoked.'));
    }

    public function promote(int $userId, UserDirectory $directory): void
    {
        $this->authorize('install-admin');
        $directory->promote($this->findUser($userId));
        Flux::toast(text: __('User promoted to organization admin.'));
    }

    public function demote(int $userId, UserDirectory $directory): void
    {
        $this->authorize('install-admin');
        $directory->demote($this->findUser($userId));
        Flux::toast(text: __('User is now an organization member.'));
    }

    public function deactivate(int $userId, UserDirectory $directory): void
    {
        $this->authorize('install-admin');
        $directory->deactivate($this->findUser($userId), Auth::user());
        Flux::toast(text: __('User deactivated.'));
    }

    public function reactivate(int $userId, UserDirectory $directory): void
    {
        $this->authorize('install-admin');
        $directory->reactivate($this->findUser($userId));
        Flux::toast(text: __('User reactivated.'));
    }

    public function generateResetLink(int $userId, UserDirectory $directory): void
    {
        $this->authorize('install-admin');

        $user = $this->findUser($userId);
        $this->resetLink = $directory->passwordResetLink($user);
        $this->resetLinkFor = $user->email;
    }

    protected function findUser(int $id): User
    {
        return Organization::current()->users()->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Users')" :subheading="__('Everyone with an account in this organization. Members get their powers from their workspace roles.')" />

    <flux:error name="user" class="mb-4" />

    <form wire:submit="invite" class="mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model="email" type="email" :label="__('Invite by email')" placeholder="name@example.com" required />
        </div>
        <flux:select wire:model="installRole" :label="__('Organization role')" class="w-44">
            @foreach (InstallRole::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" icon="envelope">{{ __('Invite') }}</flux:button>
    </form>

    @if ($inviteLink)
        <flux:callout icon="envelope" class="mb-6" :heading="__('Invitation sent')">
            <flux:callout.text>{{ __('We emailed the invitation. You can also share this link; it is shown only once.') }}</flux:callout.text>
            <flux:input :value="$inviteLink" readonly copyable class="mt-2" />
        </flux:callout>
    @endif

    @if ($resetLink)
        <flux:callout icon="key" class="mb-6" :heading="__('Password reset link for :email', ['email' => $resetLinkFor])">
            <flux:callout.text>{{ __('Give this link to the user. It works once and expires in :minutes minutes.', ['minutes' => config('auth.passwords.users.expire')]) }}</flux:callout.text>
            <flux:input :value="$resetLink" readonly copyable class="mt-2" data-test="reset-link" />
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Organization role') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->users as $listedUser)
                @php($membership = $listedUser->membership)
                <flux:table.row :key="$listedUser->id">
                    <flux:table.cell>{{ $listedUser->name }}</flux:table.cell>
                    <flux:table.cell>{{ $listedUser->email }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$membership->role === InstallRole::Admin ? 'indigo' : 'zinc'">{{ $membership->role->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($membership->deactivated_at)
                            <flux:badge size="sm" color="red">{{ __('Deactivated') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Actions')" />
                            <flux:menu>
                                @if ($membership->role === InstallRole::Admin)
                                    <flux:menu.item wire:click="demote({{ $listedUser->id }})">{{ __('Demote to member') }}</flux:menu.item>
                                @else
                                    <flux:menu.item wire:click="promote({{ $listedUser->id }})">{{ __('Promote to organization admin') }}</flux:menu.item>
                                @endif
                                <flux:menu.item icon="key" wire:click="generateResetLink({{ $listedUser->id }})">{{ __('Generate password-reset link') }}</flux:menu.item>
                                <flux:menu.separator />
                                @if ($membership->deactivated_at)
                                    <flux:menu.item wire:click="reactivate({{ $listedUser->id }})">{{ __('Reactivate') }}</flux:menu.item>
                                @else
                                    <flux:menu.item variant="danger" wire:click="deactivate({{ $listedUser->id }})"
                                        wire:confirm="{{ __('Deactivate :name? They are signed out and cannot sign in until reactivated.', ['name' => $listedUser->name]) }}">
                                        {{ __('Deactivate') }}
                                    </flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->invitations->isNotEmpty())
        <flux:heading class="mt-10 mb-2">{{ __('Pending invitations') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Organization role') }}</flux:table.column>
                <flux:table.column>{{ __('Expires') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->invitations as $invitation)
                    <flux:table.row :key="'invitation-'.$invitation->id">
                        <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->install_role->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->expires_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})">{{ __('Revoke') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
