<?php

namespace Tests\Feature\Admin;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Secret;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManageWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['admin.workspaces'])]
    #[TestWith(['admin.users'])]
    #[TestWith(['admin.settings'])]
    #[TestWith(['admin.audit'])]
    public function test_forbids_install_members_from_admin_pages(string $route): void
    {
        $workspace = Workspace::factory()->create();
        $workspaceAdmin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($workspaceAdmin)->get(route($route))->assertForbidden();
    }

    public function test_install_admin_creates_renames_and_deletes_a_workspace(): void
    {
        $admin = User::factory()->installAdmin()->create();

        $page = Livewire::actingAs($admin)->test('pages::admin.workspaces');

        $page->set('name', 'Platform Ops')->call('save')->assertHasNoErrors();
        $workspace = Workspace::firstWhere('name', 'Platform Ops');
        $this->assertSame('platform-ops', $workspace->slug);
        $this->assertSame(Organization::current()->id, $workspace->organization_id);

        $page->call('edit', $workspace->id)->set('name', 'Platform')->call('save');
        $this->assertSame('platform', $workspace->fresh()->slug);

        Secret::factory()->for($workspace)->create();
        $page->call('delete', $workspace->id);
        $this->assertModelMissing($workspace);
        $this->assertDatabaseCount('secrets', 0);

        $this->assertSame(
            ['workspace.created', 'workspace.renamed', 'workspace.deleted'],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_slugs_stay_unique_for_duplicate_names(): void
    {
        $first = Workspace::factory()->create(['name' => 'Sales']);
        $second = Workspace::factory()->create(['name' => 'Sales']);

        $this->assertSame('sales', $first->slug);
        $this->assertSame('sales-2', $second->slug);
    }
}
