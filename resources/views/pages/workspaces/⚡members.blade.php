<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invitations;
use App\Services\WorkspaceManager;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Workspace $workspace;

    public string $email = '';

    public string $role = 'editor';

    public ?string $inviteLink = null;

    #[Computed]
    public function members()
    {
        return $this->workspace->members()->orderBy('name')->get();
    }

    #[Computed]
    public function invitations()
    {
        return $this->workspace->invitations()->pending()->latest()->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-members', $this->workspace);
    }

    public function add(WorkspaceManager $workspaces, Invitations $invitations): void
    {
        $this->authorize('manage-members', $this->workspace);

        $this->email = Str::lower(trim($this->email));
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ]);

        $role = WorkspaceRole::from($this->role);
        $user = User::query()->where('email', $this->email)->first();

        if ($user) {
            $workspaces->addMember($this->workspace, $user, $role);
            $this->inviteLink = null;
            Flux::toast(variant: 'success', text: __('workspaces.members.name_now_has_access_as', ['name' => $user->name, 'role' => $role->label()]));
        } else {
            [, $token] = $invitations->invite($this->workspace->organization, $this->email, Auth::user(), workspace: $this->workspace, workspaceRole: $role);
            $this->inviteLink = route('invitations.show', $token);
            Flux::toast(variant: 'success', text: __('workspaces.members.invitation_sent_to_email', ['email' => $this->email]));
        }

        $this->reset('email');
    }

    public function changeRole(string $userId, string $role, WorkspaceManager $workspaces): void
    {
        $this->authorize('manage-members', $this->workspace);

        $user = $this->workspace->members()->findOrFail($userId);
        $workspaces->changeRole($this->workspace, $user, WorkspaceRole::from($role));

        Flux::toast(text: __('workspaces.members.role_updated'));
    }

    public function remove(string $userId, WorkspaceManager $workspaces): void
    {
        $this->authorize('manage-members', $this->workspace);

        $user = $this->workspace->members()->findOrFail($userId);
        $workspaces->removeMember($this->workspace, $user);

        Flux::toast(text: __('workspaces.members.member_removed'));
    }

    public function revoke(int $invitationId, Invitations $invitations): void
    {
        $this->authorize('manage-members', $this->workspace);

        $invitations->revoke($this->workspace->invitations()->findOrFail($invitationId));

        Flux::toast(text: __('common.invitation_revoked'));
    }

    public function render()
    {
        return $this->view()->title(__('common.members').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.members')" :subheading="__('workspaces.members.who_can_use_this_workspace')" />

    @if ($this->canManage)
        <form wire:submit="add" class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <flux:input wire:model="email" type="email" :label="__('workspaces.members.add_or_invite_by_email')" placeholder="name@example.com" required />
            </div>
            <flux:select wire:model="role" :label="__('workspaces.members.role')" class="w-40">
                @foreach (WorkspaceRole::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="user-plus">{{ __('common.add') }}</flux:button>
        </form>

        @if ($inviteLink)
            <flux:callout icon="envelope" class="mb-6" :heading="__('common.invitation_sent')">
                <flux:callout.text>{{ __('common.we_emailed_the_invitation_you') }}</flux:callout.text>
                <flux:input :value="$inviteLink" readonly copyable class="mt-2" data-test="invite-link" />
            </flux:callout>
        @endif
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.name') }}</flux:table.column>
            <flux:table.column>{{ __('common.email') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.members.role') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->members as $member)
                <flux:table.row :key="$member->id">
                    <flux:table.cell>{{ $member->name }}</flux:table.cell>
                    <flux:table.cell>{{ $member->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($this->canManage)
                            <flux:select size="sm" class="w-32" wire:change="changeRole('{{ $member->id }}', $event.target.value)">
                                @foreach (WorkspaceRole::cases() as $option)
                                    <flux:select.option :value="$option->value" :selected="$member->membership->role === $option">{{ $option->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:badge size="sm">{{ $member->membership->role->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="remove('{{ $member->id }}')"
                                wire:confirm="{{ __('workspaces.members.remove_name_from_this_workspace', ['name' => $member->name]) }}" :aria-label="__('common.remove')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('workspaces.members.no_members_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    @if ($this->canManage && $this->invitations->isNotEmpty())
        <flux:heading class="mt-10 mb-2">{{ __('common.pending_invitations') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('common.email') }}</flux:table.column>
                <flux:table.column>{{ __('workspaces.members.role') }}</flux:table.column>
                <flux:table.column>{{ __('common.expires') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->invitations as $invitation)
                    <flux:table.row :key="'invitation-'.$invitation->id">
                        <flux:table.cell>{{ $invitation->email }}</flux:table.cell>
                        <flux:table.cell>{{ $invitation->workspace_role?->label() }}</flux:table.cell>
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
