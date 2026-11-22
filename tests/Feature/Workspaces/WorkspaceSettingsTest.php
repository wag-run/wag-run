<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_a_normalized_allowlist_and_custom_retention(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.settings', ['workspace' => $workspace])
            ->set('allowlist', "10.0.0.0/8\n*.Corp.Internal, 10.0.0.0/8")
            ->set('customRetention', true)
            ->set('retentionDays', 7)
            ->call('save')
            ->assertHasNoErrors();

        $workspace->refresh();
        $this->assertSame(['10.0.0.0/8', '*.corp.internal'], $workspace->internal_allowlist);
        $this->assertSame(7, $workspace->effectiveRetentionDays());
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.settings_updated', 'workspace_id' => $workspace->id]);
    }

    public function test_rejects_invalid_allowlist_entries_by_name(): void
    {
        $workspace = Workspace::factory()->create(['internal_allowlist' => ['10.0.0.1']]);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $page = Livewire::actingAs($admin)
            ->test('pages::workspaces.settings', ['workspace' => $workspace])
            ->set('allowlist', "10.0.0.0/8\nhttp://bad")
            ->call('save');

        $this->assertSame('Not a valid host, IP address or CIDR range: http://bad', $page->errors()->first('allowlist'));

        $this->assertSame(['10.0.0.1'], $workspace->fresh()->internal_allowlist);
    }

    public function test_retention_falls_back_to_the_install_default(): void
    {
        Organization::factory()->create(['default_retention_days' => 90]);
        $workspace = Workspace::factory()->create(['retention_days' => 14]);
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.settings', ['workspace' => $workspace])
            ->set('customRetention', false)
            ->call('save');

        $this->assertNull($workspace->fresh()->retention_days);
        $this->assertSame(90, $workspace->fresh()->effectiveRetentionDays());
    }
}
