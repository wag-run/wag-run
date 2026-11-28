<?php

namespace App\Services;

use App\Enums\OrgRole;
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

    /**
     * Give someone their first workspace in an organization, "Ada's workspace",
     * with them as its Admin, and make it their current one.
     */
    public function createFirstFor(User $user, Organization $organization): Workspace
    {
        return DB::transaction(function () use ($user, $organization) {
            $workspace = $organization->workspaces()->create([
                'name' => __('workspaces.first_workspace_name', ['name' => $user->firstName()]),
            ]);
            $workspace->members()->attach($user, ['role' => WorkspaceRole::Admin->value]);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            $organization->users()->updateExistingPivot($user->id, ['first_workspace_at' => now()]);

            Audit::record('workspace.created', $workspace, $workspace, actor: $user);

            return $workspace;
        });
    }

    /**
     * On someone's first sign-in to each organization they're active in, give
     * them a workspace when they have none there and the organization allows it.
     * It happens once: someone who already has a workspace, or whose
     * organization has it off, is only marked as handled, so they never get
     * one later, even if they're removed from every workspace.
     */
    public function welcome(User $user): void
    {
        $memberships = $user->organizations()->wherePivotNull('deactivated_at')->wherePivotNull('first_workspace_at')->get();

        foreach ($memberships as $organization) {
            $hasWorkspace = $user->workspaces()->where('organization_id', $organization->id)->exists();

            if (! $hasWorkspace && $organization->first_workspaces) {
                $this->createFirstFor($user, $organization);

                continue;
            }

            $organization->users()->updateExistingPivot($user->id, ['first_workspace_at' => now()]);
        }
    }

    public function rename(Workspace $workspace, string $name): void
    {
        $from = $workspace->name;

        $workspace->update([
            'name' => $name,
            'slug' => Workspace::slugFor($name),
        ]);

        Audit::record('workspace.renamed', $workspace, $workspace, ['from' => $from, 'to' => $name]);
    }

    public function delete(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            Audit::record('workspace.deleted', $workspace, null, ['workspace' => $workspace->name], $workspace->name);

            app(WorkflowFiles::class)->deleteForWorkspace($workspace);
            $workspace->delete();
        });
    }

    /**
     * Grant a user access to a workspace. Users who are not yet part of the
     * organization join it as organization Members.
     */
    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        DB::transaction(function () use ($workspace, $user, $role) {
            if (! $user->organizations()->whereKey($workspace->organization_id)->exists()) {
                $user->organizations()->attach($workspace->organization_id, ['role' => OrgRole::Member->value]);
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

        $changed = $workspace->changedFields();
        $workspace->save();

        Audit::record('workspace.settings_updated', $workspace, $workspace, ['changed' => $changed]);
    }
}
