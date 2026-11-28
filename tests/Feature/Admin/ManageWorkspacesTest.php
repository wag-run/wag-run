<?php

namespace Tests\Feature\Admin;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
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
    #[TestWith(['admin.email'])]
    #[TestWith(['admin.audit'])]
    public function test_forbids_org_members_from_admin_pages(string $route): void
    {
        $workspace = Workspace::factory()->create();
        $workspaceAdmin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($workspaceAdmin)->get(route($route))->assertForbidden();
    }

    public function test_org_admin_creates_renames_and_deletes_a_workspace(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        $page = Livewire::actingAs($admin)->test('pages::admin.workspaces');

        $page->set('name', 'Platform Ops')->call('save')->assertHasNoErrors();
        $workspace = Workspace::firstWhere('name', 'Platform Ops');
        $this->assertMatchesRegularExpression('/^platform-ops-[a-z0-9]{6}$/', $workspace->slug);
        $this->assertSame($this->organization()->id, $workspace->organization_id);

        $page->call('edit', $workspace->id)->set('name', 'Platform')->call('save');
        $this->assertMatchesRegularExpression('/^platform-[a-z0-9]{6}$/', $workspace->fresh()->slug);

        Secret::factory()->for($workspace)->create();
        $page->call('delete', $workspace->id);
        $this->assertModelMissing($workspace);
        $this->assertDatabaseCount('secrets', 0);

        $this->assertSame(
            ['workspace.created', 'workspace.renamed', 'workspace.deleted'],
            AuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_the_workspace_form_focuses_the_name(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        $html = Livewire::actingAs($admin)->test('pages::admin.workspaces')->call('create')->html();

        $this->assertMatchesRegularExpression('/<input(?=[^>]*\\bautofocus\\b)(?=[^>]*data-test="workspace-name")[^>]*>/', $html);
    }

    public function test_slugs_stay_unique_for_duplicate_names(): void
    {
        $first = Workspace::factory()->create(['name' => 'Sales']);
        $second = Workspace::factory()->create(['name' => 'Sales']);

        $this->assertMatchesRegularExpression('/^sales-[a-z0-9]{6}$/', $first->slug);
        $this->assertMatchesRegularExpression('/^sales-[a-z0-9]{6}$/', $second->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_a_long_or_symbol_only_name_still_gets_a_short_slug(): void
    {
        $long = Workspace::factory()->create(['name' => str_repeat('Analytical engine ', 10)]);
        $symbols = Workspace::factory()->create(['name' => '!!!']);

        $this->assertLessThanOrEqual(47, strlen($long->slug));
        $this->assertMatchesRegularExpression('/^analytical-engine-.*[a-z0-9]-[a-z0-9]{6}$/', $long->slug);
        $this->assertMatchesRegularExpression('/^workspace-[a-z0-9]{6}$/', $symbols->slug);
    }
}
