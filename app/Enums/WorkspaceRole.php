<?php

namespace App\Enums;

use App\Enums\WorkspacePermission as P;

enum WorkspaceRole: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('enums.workspace_role.admin'),
            self::Editor => __('enums.workspace_role.editor'),
            self::Viewer => __('enums.workspace_role.viewer'),
            self::Guest => __('enums.workspace_role.guest'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('enums.workspace_role.manages_members_secrets_credentials_and'),
            self::Editor => __('enums.workspace_role.builds_publishes_and_runs_workflows'),
            self::Viewer => __('enums.workspace_role.sees_workflows_and_execution_logs'),
            self::Guest => __('enums.workspace_role.sees_only_the_approvals_and'),
        };
    }

    /**
     * @return list<P>
     */
    public function permissions(): array
    {
        $guest = [P::ViewOwnAssignments];

        // Viewers see that variables, secrets and authentication settings exist
        // (names only) so they can read workflow configuration that references them.
        $viewer = [...$guest, P::ViewWorkflows, P::ViewExecutions, P::ViewVariables, P::ViewSecrets, P::ViewCredentials, P::ViewMembers];

        $editor = [...$viewer, P::EditWorkflows, P::PublishWorkflows, P::RunWorkflows, P::CancelExecutions, P::ManageVariables, P::ViewStore, P::ManageStore];

        return match ($this) {
            self::Guest => $guest,
            self::Viewer => $viewer,
            self::Editor => $editor,
            self::Admin => P::cases(),
        };
    }

    public function can(P $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
