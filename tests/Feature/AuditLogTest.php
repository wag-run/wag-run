<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admins_see_only_their_workspaces_entries(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        Audit::record('data_store.entry_created', workspace: $mine, label: 'visible_entry');
        Audit::record('data_store.entry_created', workspace: $theirs, label: 'other_workspace_entry');
        Audit::record('user.promoted', label: 'org_entry', organization: $this->organization());
        $admin = User::factory()->memberOf($mine, WorkspaceRole::Admin)->create();

        $this->assertSame(['visible_entry'], AuditLog::visibleTo($admin)->pluck('subject_label')->all());

        $this->actingAs($admin)
            ->get(route('workspaces.audit', $mine))
            ->assertOk()
            ->assertSee('visible_entry')
            ->assertDontSee('other_workspace_entry');
    }

    public function test_organization_scope_shows_org_admins_every_entry(): void
    {
        $workspace = Workspace::factory()->create();
        $deleted = Workspace::factory()->create();
        Audit::record('data_store.entry_created', workspace: Workspace::factory()->create(), label: 'workspace_entry');
        Audit::record('data_store.entry_created', workspace: $deleted, label: 'retired_entry');
        Audit::record('user.promoted', label: 'org_entry', organization: $this->organization());
        $deleted->delete();
        $orgAdmin = User::factory()->orgAdmin()->create();

        $this->assertCount(3, AuditLog::visibleTo($orgAdmin)->get());

        $this->actingAs($orgAdmin)
            ->get(route('workspaces.audit', [$workspace, 'scope' => 'organization']))
            ->assertOk()
            ->assertSee('workspace_entry')
            ->assertSee('retired_entry')
            ->assertSee('org_entry');
    }

    public function test_workspace_scope_hides_organization_entries_from_org_admins(): void
    {
        $workspace = Workspace::factory()->create();
        Audit::record('data_store.entry_created', workspace: $workspace, label: 'workspace_entry');
        Audit::record('user.promoted', label: 'org_entry', organization: $this->organization());

        $this->actingAs(User::factory()->orgAdmin()->create())
            ->get(route('workspaces.audit', $workspace))
            ->assertOk()
            ->assertSee('workspace_entry')
            ->assertDontSee('org_entry');
    }

    public function test_forbids_workspace_admins_from_the_organization_scope(): void
    {
        $workspace = Workspace::factory()->create();
        Audit::record('user.promoted', label: 'org_entry', organization: $this->organization());
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($admin)
            ->get(route('workspaces.audit', [$workspace, 'scope' => 'organization']))
            ->assertForbidden();
    }

    public function test_workspace_admins_cannot_switch_the_scope_through_livewire(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($admin)
            ->test('pages::workspaces.audit', ['workspace' => $workspace])
            ->set('scope', 'organization');
    }

    public function test_rejects_an_unknown_scope(): void
    {
        $workspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->orgAdmin()->create())
            ->get(route('workspaces.audit', [$workspace, 'scope' => 'everything']))
            ->assertNotFound();
    }

    public function test_forbids_the_log_of_a_workspace_the_user_does_not_belong_to(): void
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();
        $admin = User::factory()->memberOf($mine, WorkspaceRole::Admin)->create();

        $this->actingAs($admin)->get(route('workspaces.audit', $theirs))->assertForbidden();
    }

    public function test_dropdown_lists_only_workspaces_whose_log_the_user_may_read(): void
    {
        $administered = Workspace::factory()->create(['name' => 'Administered']);
        $edited = Workspace::factory()->create(['name' => 'Edited']);
        Workspace::factory()->create(['name' => 'Unrelated']);
        $user = User::factory()->memberOf($administered, WorkspaceRole::Admin)->create();
        $edited->members()->attach($user, ['role' => WorkspaceRole::Editor->value]);

        Livewire::actingAs($user)
            ->test('pages::workspaces.audit', ['workspace' => $administered])
            ->assertSee('Administered')
            ->assertDontSee('Edited')
            ->assertDontSee('Unrelated')
            ->assertDontSee(route('workspaces.audit', [$administered, 'scope' => 'organization']));
    }

    public function test_dropdown_offers_org_admins_every_workspace_and_the_organization_scope(): void
    {
        $first = Workspace::factory()->create(['name' => 'First']);
        $second = Workspace::factory()->create(['name' => 'Second']);

        Livewire::actingAs(User::factory()->orgAdmin()->create())
            ->test('pages::workspaces.audit', ['workspace' => $first])
            ->assertSee(route('workspaces.audit', $second))
            ->assertSee(route('workspaces.audit', [$first, 'scope' => 'organization']));
    }

    public function test_filters_entries_by_action(): void
    {
        $workspace = Workspace::factory()->create();
        Audit::record('data_store.entry_created', workspace: $workspace, label: 'data_store_entry');
        Audit::record('secret.created', workspace: $workspace, label: 'secret_entry');
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.audit', ['workspace' => $workspace])
            ->set('action', 'secret')
            ->assertSee('secret_entry')
            ->assertDontSee('data_store_entry');
    }

    public function test_old_organization_url_redirects_to_the_organization_scope(): void
    {
        $workspace = Workspace::factory()->create();

        $this->actingAs(User::factory()->orgAdmin()->create())
            ->get(route('admin.audit'))
            ->assertRedirect(route('workspaces.audit', [$workspace, 'scope' => 'organization']));
    }

    public function test_old_organization_url_redirects_to_workspaces_when_there_are_none(): void
    {
        $this->actingAs(User::factory()->orgAdmin()->create())
            ->get(route('admin.audit'))
            ->assertRedirect(route('admin.workspaces'));
    }

    public function test_records_the_acting_user_and_request_ip(): void
    {
        $user = User::factory()->orgAdmin()->create();
        $this->actingAs($user);

        $entry = Audit::record('organization.settings_updated');

        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('127.0.0.1', $entry->ip_address);
    }

    public function test_escapes_user_supplied_labels(): void
    {
        $workspace = Workspace::factory()->create();
        Audit::record('data_store.entry_created', workspace: $workspace, label: '<script>alert(1)</script>');
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $this->actingAs($admin)
            ->get(route('workspaces.audit', $workspace))
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
