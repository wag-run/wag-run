<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admins_see_only_their_workspaces_entries(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        Audit::record('variable.created', workspace: $mine, label: 'visible_entry');
        Audit::record('variable.created', workspace: $theirs, label: 'other_workspace_entry');
        Audit::record('user.promoted', label: 'install_entry');
        $admin = User::factory()->memberOf($mine, WorkspaceRole::Admin)->create();

        $this->assertSame(['visible_entry'], AuditLog::visibleTo($admin)->pluck('subject_label')->all());

        $this->actingAs($admin)
            ->get(route('workspaces.audit', $mine))
            ->assertOk()
            ->assertSee('visible_entry')
            ->assertDontSee('other_workspace_entry');
    }

    public function test_install_admins_see_every_entry(): void
    {
        Audit::record('variable.created', workspace: Workspace::factory()->create(), label: 'workspace_entry');
        Audit::record('user.promoted', label: 'install_entry');
        $installAdmin = User::factory()->installAdmin()->create();

        $this->assertCount(2, AuditLog::visibleTo($installAdmin)->get());

        $this->actingAs($installAdmin)
            ->get(route('admin.audit'))
            ->assertOk()
            ->assertSee('workspace_entry')
            ->assertSee('install_entry');
    }

    public function test_records_the_acting_user_and_request_ip(): void
    {
        $user = User::factory()->installAdmin()->create();
        $this->actingAs($user);

        $entry = Audit::record('organization.settings_updated');

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }

    public function test_escapes_user_supplied_labels(): void
    {
        $workspace = Workspace::factory()->create();
        Audit::record('variable.created', workspace: $workspace, label: '<script>alert(1)</script>');
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($admin)
            ->get(route('workspaces.audit', $workspace))
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
