<?php

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\OrgRole;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property string $id a UUID
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property int|null $current_workspace_id
 * @property array<string, array{database?: bool, mail?: bool}>|null $notification_preferences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Whether this person wants a kind of notification on a channel, `database`
     * (in the app) or `mail`. Both are on until they turn one off.
     */
    public function wantsNotification(NotificationKind $kind, string $channel): bool
    {
        return (bool) ($this->notification_preferences[$kind->value][$channel] ?? true);
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationMembership, 'membership'>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(OrganizationMembership::class)
            ->as('membership')
            ->withPivot(['role', 'deactivated_at'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'membership'>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * The user's membership of the organization this install serves.
     */
    public function organizationMembership(): ?OrganizationMembership
    {
        $organization = Organization::current();

        if (! $organization) {
            return null;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $this->id)
            ->first();
    }

    /**
     * The user's own membership of a workspace, ignoring organization-admin powers.
     */
    public function workspaceMembership(Workspace $workspace): ?WorkspaceMembership
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $this->id)
            ->first();
    }

    public function orgRole(): ?OrgRole
    {
        return $this->organizationMembership()?->role;
    }

    public function isOrgAdmin(): bool
    {
        $membership = $this->organizationMembership();

        return $membership?->role === OrgRole::Admin && $membership->deactivated_at === null;
    }

    public function isDeactivated(): bool
    {
        return $this->organizationMembership()?->deactivated_at !== null;
    }

    /**
     * The user's effective role in a workspace. Organization admins act as Admin in
     * every workspace of their organization without being added as a member.
     */
    public function roleIn(Workspace $workspace): ?WorkspaceRole
    {
        if ($this->isOrgAdmin() && $this->organizationMembership()?->organization_id === $workspace->organization_id) {
            return WorkspaceRole::Admin;
        }

        return $this->workspaceMembership($workspace)?->role;
    }

    public function hasWorkspacePermission(Workspace $workspace, WorkspacePermission $permission): bool
    {
        return (bool) $this->roleIn($workspace)?->can($permission);
    }

    /**
     * Workspaces the user can move between: every workspace for organization
     * admins, else the ones they are a member of (any role, Guest included).
     *
     * @return Collection<int, Workspace>
     */
    public function accessibleWorkspaces(): Collection
    {
        if ($this->isOrgAdmin()) {
            return Workspace::query()
                ->where('organization_id', $this->organizationMembership()?->organization_id)
                ->orderBy('name')
                ->get();
        }

        return $this->workspaces()->orderBy('name')->get();
    }

    /**
     * IDs of workspaces where the user is a member with the Admin role.
     *
     * @return list<int>
     */
    public function administeredWorkspaceIds(): array
    {
        return array_values(WorkspaceMembership::query()
            ->where('user_id', $this->id)
            ->where('role', WorkspaceRole::Admin->value)
            ->pluck('workspace_id')
            ->map(fn (mixed $id): int => intval($id))
            ->all());
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
