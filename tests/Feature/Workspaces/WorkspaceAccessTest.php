<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, WorkspaceRole}>
     */
    public static function refusedPages(): array
    {
        return [
            'guest on variables' => ['workspaces.variables', WorkspaceRole::Guest],
            'guest on secrets' => ['workspaces.secrets', WorkspaceRole::Guest],
            'guest on members' => ['workspaces.members', WorkspaceRole::Guest],
            'viewer on settings' => ['workspaces.settings', WorkspaceRole::Viewer],
            'editor on settings' => ['workspaces.settings', WorkspaceRole::Editor],
            'editor on audit log' => ['workspaces.audit', WorkspaceRole::Editor],
        ];
    }

    #[DataProvider('refusedPages')]
    public function test_forbids_pages_above_the_members_role(string $route, WorkspaceRole $role): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, $role)->create();

        $this->actingAs($user)->get(route($route, $workspace))->assertForbidden();
    }

    /**
     * @return array<string, array{string, WorkspaceRole}>
     */
    public static function allowedPages(): array
    {
        return [
            'guest on overview' => ['workspaces.show', WorkspaceRole::Guest],
            'viewer on variables' => ['workspaces.variables', WorkspaceRole::Viewer],
            'viewer on secret names' => ['workspaces.secrets', WorkspaceRole::Viewer],
            'viewer on authentication' => ['workspaces.credentials', WorkspaceRole::Viewer],
            'viewer on members' => ['workspaces.members', WorkspaceRole::Viewer],
            'admin on settings' => ['workspaces.settings', WorkspaceRole::Admin],
            'admin on audit log' => ['workspaces.audit', WorkspaceRole::Admin],
        ];
    }

    #[DataProvider('allowedPages')]
    public function test_renders_pages_the_members_role_allows(string $route, WorkspaceRole $role): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, $role)->create();

        $this->actingAs($user)->get(route($route, $workspace))->assertOk();
    }

    public function test_forbids_users_who_are_not_members(): void
    {
        $workspace = Workspace::factory()->create();
        $outsider = User::factory()->orgMember()->create();

        $this->actingAs($outsider)->get(route('workspaces.show', $workspace))->assertForbidden();
    }

    public function test_org_admin_acts_as_admin_without_membership(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->orgAdmin()->create();

        $this->actingAs($admin)->get(route('workspaces.settings', $workspace))->assertOk();
        $this->assertSame(WorkspaceRole::Admin, $admin->roleIn($workspace));
    }

    public function test_org_admin_has_no_role_in_another_organizations_workspace(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $foreign = Workspace::factory()->for(Organization::factory())->create();

        $this->actingAs($admin)->get(route('workspaces.show', $foreign))->assertForbidden();
    }

    public function test_dashboard_redirects_to_the_last_opened_workspace(): void
    {
        $first = Workspace::factory()->create(['name' => 'Alpha']);
        $second = Workspace::factory()->create(['name' => 'Beta']);
        $user = User::factory()->memberOf($first, WorkspaceRole::Viewer)->memberOf($second, WorkspaceRole::Viewer)->create();

        $this->actingAs($user)->get(route('workspaces.show', $second))->assertOk();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('workspaces.show', $second));
    }

    public function test_sidebar_switcher_lists_only_accessible_workspaces(): void
    {
        $mine = Workspace::factory()->create(['name' => 'Mine']);
        Workspace::factory()->create(['name' => 'Hidden Workspace']);
        $user = User::factory()->memberOf($mine, WorkspaceRole::Editor)->create();

        $this->actingAs($user)->get(route('workspaces.show', $mine))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Hidden Workspace');
    }
}
