<?php

namespace App\Services;

use App\Enums\InstallRole;
use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class WorkspaceManager
{
    public function create(Organization $organization, string $name, ?User $actor = null): Workspace
    {
        return DB::transaction(function () use ($organization, $name, $actor) {
            $workspace = $organization->workspaces()->create(['name' => $name]);

            Audit::record('workspace.created', $workspace, $workspace, actor: $actor);

            return $workspace;
        });
    }

    public function rename(Workspace $workspace, string $name): void
    {
        $from = $workspace->name;

        $workspace->update([
            'name' => $name,
            'slug' => Workspace::uniqueSlug($name, $workspace->organization_id, $workspace->id),
        ]);

        Audit::record('workspace.renamed', $workspace, $workspace, ['from' => $from, 'to' => $name]);
    }

    public function delete(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            Audit::record('workspace.deleted', $workspace, null, ['workspace' => $workspace->name], $workspace->name);

            $workspace->delete();
        });
    }

    /**
     * Grant a user access to a workspace. Users who are not yet part of the
     * organization join it as install Members.
     */
    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        DB::transaction(function () use ($workspace, $user, $role) {
            if (! $user->organizations()->whereKey($workspace->organization_id)->exists()) {
                $user->organizations()->attach($workspace->organization_id, ['role' => InstallRole::Member->value]);
            }

            if ($workspace->members()->whereKey($user->id)->exists()) {
                $this->changeRole($workspace, $user, $role);

                return;
            }

            $workspace->members()->attach($user, ['role' => $role->value]);

            Audit::record('member.added', $user, $workspace, ['role' => $role->value]);
        });
    }

    public function changeRole(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        $current = $user->workspaceMembership($workspace)?->role;

        if ($current === $role) {
            return;
        }

        $workspace->members()->updateExistingPivot($user->id, ['role' => $role->value]);

        Audit::record('member.role_changed', $user, $workspace, ['from' => $current?->value, 'to' => $role->value]);
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        if ($workspace->members()->detach($user->id) > 0) {
            Audit::record('member.removed', $user, $workspace);
        }
    }

    /**
     * @param  list<string>  $allowlist
     */
    public function updateSettings(Workspace $workspace, ?int $retentionDays, array $allowlist): void
    {
        $workspace->fill([
            'retention_days' => $retentionDays,
            'internal_allowlist' => $allowlist,
        ]);

        if (! $workspace->isDirty()) {
            return;
        }

        $changes = $workspace->getDirty();
        $workspace->save();

        Audit::record('workspace.settings_updated', $workspace, $workspace, ['changed' => array_keys($changes)]);
    }
}
