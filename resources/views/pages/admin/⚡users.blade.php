<?php

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invitations;
use App\Services\UserDirectory;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $email = '';

    public string $orgRole = 'member';

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
        $this->authorize('org-admin');

        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'orgRole' => ['required', Rule::enum(OrgRole::class)],
        ]);

        [, $token] = $invitations->invite(Organization::current(), $this->email, Auth::user(), OrgRole::from($this->orgRole));

        $this->inviteLink = route('invitations.show', $token);
        $this->reset('email', 'orgRole');
        Flux::toast(variant: 'success', text: __('admin.users.invitation_sent'));
    }

    public function revoke(int $invitationId, Invitations $invitations): void
    {
        $this->authorize('org-admin');

        $invitations->revoke(Organization::current()->invitations()->findOrFail($invitationId));
        Flux::toast(text: __('common.invitation_revoked'));
    }

    public function promote(string $userId, UserDirectory $directory): void
    {
        $this->authorize('org-admin');
        $directory->promote($this->findUser($userId));
        Flux::toast(text: __('admin.users.user_promoted_to_organization_admin'));
    }

    public function demote(string $userId, UserDirectory $directory): void
    {
        $this->authorize('org-admin');
        $directory->demote($this->findUser($userId));
        Flux::toast(text: __('admin.users.user_is_now_an_organization'));
    }

    public function deactivate(string $userId, UserDirectory $directory): void
    {
        $this->authorize('org-admin');
        $directory->deactivate($this->findUser($userId), Auth::user());
        Flux::toast(text: __('admin.users.user_deactivated'));
    }

    public function reactivate(string $userId, UserDirectory $directory): void
    {
        $this->authorize('org-admin');
        $directory->reactivate($this->findUser($userId));
        Flux::toast(text: __('admin.users.user_reactivated'));
    }

    public function generateResetLink(string $userId, UserDirectory $directory): void
    {
        $this->authorize('org-admin');

        $user = $this->findUser($userId);
        $this->resetLink = $directory->passwordResetLink($user);
        $this->resetLinkFor = $user->email;
    }

    protected function findUser(string $id): User
    {
        return Organization::current()->users()->findOrFail($id);
    }

    public function render()
    {
        return $this->view()->title(__('admin.users.title'));
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.users')" :subheading="__('admin.users.everyone_with_an_account_in')" />

    <flux:error name="user" class="mb-4" />

    <form wire:submit="invite" class="mb-6 flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
            <flux:input wire:model="email" type="email" :label="__('admin.users.invite_by_email')" placeholder="name@example.com" required />
        </div>
        <flux:select wire:model="orgRole" :label="__('admin.users.organization_role')" class="w-44">
            @foreach (OrgRole::cases() as $option)
                <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:button type="submit" variant="primary" icon="envelope">{{ __('admin.users.invite') }}</flux:button>
    </form>

    @if ($inviteLink)
        <flux:callout icon="envelope" class="mb-6" :heading="__('common.invitation_sent')">
            <flux:callout.text>{{ __('common.we_emailed_the_invitation_you') }}</flux:callout.text>
            <flux:input :value="$inviteLink" readonly copyable class="mt-2" />
        </flux:callout>
    @endif

    @if ($resetLink)
        <flux:callout icon="key" class="mb-6" :heading="__('admin.users.password_reset_link_for_email', ['email' => $resetLinkFor])">
            <flux:callout.text>{{ __('admin.users.give_this_link_to_the', ['minutes' => config('auth.passwords.users.expire')]) }}</flux:callout.text>
            <flux:input :value="$resetLink" readonly copyable class="mt-2" data-test="reset-link" />
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.name') }}</flux:table.column>
            <flux:table.column>{{ __('common.email') }}</flux:table.column>
            <flux:table.column>{{ __('admin.users.organization_role') }}</flux:table.column>
            <flux:table.column>{{ __('common.status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->users as $listedUser)
                @php($membership = $listedUser->membership)
                <flux:table.row :key="$listedUser->id">
                    <flux:table.cell>{{ $listedUser->name }}</flux:table.cell>
                    <flux:table.cell>{{ $listedUser->email }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$membership->role === OrgRole::Admin ? 'indigo' : 'zinc'">{{ $membership->role->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($membership->deactivated_at)
                            <flux:badge size="sm" color="red">{{ __('admin.users.deactivated') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">{{ __('admin.users.active') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('common.actions')" />
                            <flux:menu>
                                @if ($membership->role === OrgRole::Admin)
                                    <flux:menu.item wire:click="demote('{{ $listedUser->id }}')">{{ __('admin.users.demote_to_member') }}</flux:menu.item>
                                @else
                                    <flux:menu.item wire:click="promote('{{ $listedUser->id }}')">{{ __('admin.users.promote_to_organization_admin') }}</flux:menu.item>
                                @endif
                                <flux:menu.item icon="key" wire:click="generateResetLink('{{ $listedUser->id }}')">{{ __('admin.users.generate_password_reset_link') }}</flux:menu.item>
                                <flux:menu.separator />
                                @if ($membership->deactivated_at)
                                    <flux:menu.item wire:click="reactivate('{{ $listedUser->id }}')">{{ __('admin.users.reactivate') }}</flux:menu.item>
                                @else
                                    <flux:menu.item variant="danger" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('admin.users.deactivate_user'), 'message' => __('admin.users.deactivate_name_they_are_signed', ['name' => $listedUser->name]), 'confirm' => __('admin.users.deactivate')]) }}).then((ok) => ok && $wire.deactivate('{{ $listedUser->id }}'))">
                                        {{ __('admin.users.deactivate') }}
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
        <flux:heading class="mt-10 mb-2">{{ __('common.pending_invitations') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('common.email') }}</flux:table.column>
                <flux:table.column>{{ __('admin.users.organization_role') }}</flux:table.column>
                <flux:table.column>{{ __('common.expires') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->invitations as $invitation)
                    <flux:table.row :key="'invitation-'.$invitation->id">
                        <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->org_role->label() }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->expires_at->diffForHumans() }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})">{{ __('common.revoke') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>
