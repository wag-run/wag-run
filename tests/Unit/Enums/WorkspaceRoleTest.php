<?php

namespace Tests\Unit\Enums;

use App\Enums\WorkspacePermission as P;
use App\Enums\WorkspaceRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkspaceRoleTest extends TestCase
{
    /**
     * The minimum role that holds each permission.
     *
     * @return array<string, array{P, WorkspaceRole}>
     */
    public static function permissionFloors(): array
    {
        return [
            'own assignments' => [P::ViewOwnAssignments, WorkspaceRole::Guest],
            'view workflows' => [P::ViewWorkflows, WorkspaceRole::Viewer],
            'view executions' => [P::ViewExecutions, WorkspaceRole::Viewer],
            'view variables' => [P::ViewVariables, WorkspaceRole::Viewer],
            'view secret names' => [P::ViewSecrets, WorkspaceRole::Viewer],
            'view credential names' => [P::ViewCredentials, WorkspaceRole::Viewer],
            'view members' => [P::ViewMembers, WorkspaceRole::Viewer],
            'edit workflows' => [P::EditWorkflows, WorkspaceRole::Editor],
            'publish workflows' => [P::PublishWorkflows, WorkspaceRole::Editor],
            'run workflows' => [P::RunWorkflows, WorkspaceRole::Editor],
            'cancel executions' => [P::CancelExecutions, WorkspaceRole::Editor],
            'manage variables' => [P::ManageVariables, WorkspaceRole::Editor],
            'manage secrets' => [P::ManageSecrets, WorkspaceRole::Admin],
            'reveal secrets' => [P::RevealSecrets, WorkspaceRole::Admin],
            'manage credentials' => [P::ManageCredentials, WorkspaceRole::Admin],
            'manage members' => [P::ManageMembers, WorkspaceRole::Admin],
            'manage settings' => [P::ManageSettings, WorkspaceRole::Admin],
            'view audit log' => [P::ViewAuditLog, WorkspaceRole::Admin],
        ];
    }

    #[DataProvider('permissionFloors')]
    public function test_grants_a_permission_to_its_floor_role_and_every_role_above_only(P $permission, WorkspaceRole $floor): void
    {
        $ladder = [WorkspaceRole::Guest, WorkspaceRole::Viewer, WorkspaceRole::Editor, WorkspaceRole::Admin];
        $floorIndex = array_search($floor, $ladder, true);

        foreach ($ladder as $index => $role) {
            $this->assertSame(
                $index >= $floorIndex,
                $role->can($permission),
                "{$role->value} ".($index >= $floorIndex ? 'should' : 'should not')." have {$permission->value}",
            );
        }
    }

    public function test_every_permission_has_a_floor_role(): void
    {
        $covered = array_map(fn (array $case) => $case[0]->value, self::permissionFloors());
        $all = array_map(fn (P $permission) => $permission->value, P::cases());

        $this->assertEqualsCanonicalizing($all, array_values($covered));
    }
}
