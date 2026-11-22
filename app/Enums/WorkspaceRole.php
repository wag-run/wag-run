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
            self::Admin => __('Admin'),
            self::Editor => __('Editor'),
            self::Viewer => __('Viewer'),
            self::Guest => __('Guest'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Manages members, secrets, authentication and workspace settings.'),
            self::Editor => __('Builds, publishes and runs workflows.'),
            self::Viewer => __('Sees workflows and execution logs.'),
            self::Guest => __('Sees only the approvals and tasks assigned to them.'),
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

        $editor = [...$viewer, P::EditWorkflows, P::PublishWorkflows, P::RunWorkflows, P::CancelExecutions, P::ManageVariables];

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
