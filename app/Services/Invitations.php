<?php

namespace App\Services;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InvitationNotification;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Invitations
{
    public const EXPIRES_IN_DAYS = 7;

    public function __construct(protected WorkspaceManager $workspaces) {}

    /**
     * Invite someone without an account on the install. Returns the plain
     * token, which is only ever available here and in the emailed link.
     *
     * @return array{0: Invitation, 1: string}
     */
    public function invite(
        Organization $organization,
        string $email,
        User $inviter,
        OrgRole $orgRole = OrgRole::Member,
        ?Workspace $workspace = null,
        ?WorkspaceRole $workspaceRole = null,
    ): array {
        $email = Str::lower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('invitations.that_person_already_has_an')]);
        }

        return DB::transaction(function () use ($organization, $email, $inviter, $orgRole, $workspace, $workspaceRole) {
            $organization->invitations()
                ->where('email', $email)
                ->where('workspace_id', $workspace?->id)
                ->whereNull('accepted_at')
                ->delete();

            $token = Str::random(48);

            $invitation = $organization->invitations()->create([
                'workspace_id' => $workspace?->id,
                'invited_by' => $inviter->id,
                'email' => $email,
                'org_role' => $orgRole,
                'workspace_role' => $workspace ? ($workspaceRole ?? WorkspaceRole::Viewer) : null,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(self::EXPIRES_IN_DAYS),
            ]);

            Audit::record('invitation.sent', $invitation, $workspace, array_filter([
                'org_role' => $orgRole->value,
                'workspace_role' => $invitation->workspace_role?->value,
            ]), $email);

            Notification::route('mail', $email)->notify(new InvitationNotification($invitation, $token));

            return [$invitation, $token];
        });
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->delete();

        Audit::record('invitation.revoked', null, $invitation->workspace, label: $invitation->email);
    }

    public function accept(Invitation $invitation, User $user): void
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages(['invitation' => __('invitations.this_invitation_has_expired_or')]);
        }

        if (Str::lower($user->email) !== $invitation->email) {
            throw ValidationException::withMessages(['invitation' => __('invitations.this_invitation_was_sent_to')]);
        }

        DB::transaction(function () use ($invitation, $user) {
            if (! $user->organizations()->whereKey($invitation->organization_id)->exists()) {
                $user->organizations()->attach($invitation->organization_id, ['role' => $invitation->org_role->value]);
            }

            if ($invitation->workspace) {
                $this->workspaces->addMember($invitation->workspace, $user, $invitation->workspace_role ?? WorkspaceRole::Viewer);
                $user->forceFill(['current_workspace_id' => $invitation->workspace_id])->save();
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            Audit::record('invitation.accepted', $user, $invitation->workspace);
        });
    }

    /**
     * Create the invitee's account and accept. The address is treated as
     * verified, because the invitation link was sent to it.
     */
    public function register(Invitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $this->accept($invitation, $user);

            return $user;
        });
    }
}
