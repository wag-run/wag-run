<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_collapses_and_has_a_resize_handle(): void
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme Ops']);
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->actingAs($user)
            ->get(route('workspaces.show', $workspace))
            ->assertOk()
            ->assertSee('collapsible="true"', false)
            ->assertSee('data-flux-sidebar-backdrop', false)
            ->assertSee('x-data="wagSidebarResize"', false)
            ->assertSee('aria-label="'.__('navigation.resize_sidebar').'"', false)
            ->assertSee(__('navigation.double_click_to_toggle_sidebar'))
            ->assertSeeInOrder(['data-test="workspace-initials"', 'AO</span>'], false);
    }
}
