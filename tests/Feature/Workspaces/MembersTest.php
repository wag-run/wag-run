<?php

namespace Tests\Feature\Workspaces;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InvitationNotification;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class MembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_grants_an_existing_user_access_with_a_role(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $colleague = User::factory()->orgMember()->create(['email' => 'colleague@example.com']);

        Livewire::actingAs($admin)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->set('email', 'Colleague@Example.com')
            ->set('role', 'editor')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('inviteLink', null);

        $this->assertSame(WorkspaceRole::Editor, $colleague->roleIn($workspace));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.added', 'subject_id' => $colleague->id, 'workspace_id' => $workspace->id]);
    }

    public function test_admin_invites_someone_without_an_account(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();

        $page = Livewire::actingAs($admin)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->set('email', 'new@example.com')
            ->set('role', 'viewer')
            ->call('add')
            ->assertHasNoErrors();

        $invitation = Invitation::firstWhere('email', 'new@example.com');

        $this->assertSame($workspace->id, $invitation->workspace_id);
        $this->assertSame(WorkspaceRole::Viewer, $invitation->workspace_role);
        $this->assertSame(OrgRole::Member, $invitation->org_role);
        $this->assertStringStartsWith(url('invitations').'/', $page->get('inviteLink'));
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@example.com');
    }

    public function test_someone_with_an_account_in_another_organization_is_invited_not_added(): void
    {
        Notification::fake();
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $stranger = User::factory()->create(['name' => 'Elsewhere Person', 'email' => 'elsewhere@example.com']);
        $stranger->organizations()->attach(Organization::factory()->create(), ['role' => OrgRole::Admin->value]);

        $page = Livewire::actingAs($admin)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->set('email', 'elsewhere@example.com')
            ->set('role', 'editor')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertNull($stranger->roleIn($workspace));
        $this->assertFalse($stranger->organizations()->whereKey($workspace->organization_id)->exists());
        $this->assertNotNull($page->get('inviteLink'));
        $this->assertSame($workspace->id, Invitation::firstWhere('email', 'elsewhere@example.com')->workspace_id);
        $page->assertDontSee('Elsewhere Person');
    }

    public function test_admin_changes_a_role_and_removes_a_member(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $member = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $page = Livewire::actingAs($admin)->test('pages::workspaces.members', ['workspace' => $workspace]);

        $page->call('changeRole', $member->id, 'editor');
        $this->assertSame(WorkspaceRole::Editor, $member->roleIn($workspace));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.role_changed', 'subject_id' => $member->id]);

        $page->call('remove', $member->id);
        $this->assertNull($member->roleIn($workspace));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.removed', 'subject_id' => $member->id]);
    }

    public function test_editor_cannot_manage_members(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $other = User::factory()->orgMember()->create();
        Notification::fake();
        [$invitation] = app(Invitations::class)->invite($workspace->organization, 'pending@example.com', $viewer, workspace: $workspace);

        Livewire::actingAs($editor)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->set('email', $other->email)
            ->call('add')
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->call('changeRole', $editor->id, 'admin')
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->call('remove', $viewer->id)
            ->assertForbidden();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->call('revoke', $invitation->id)
            ->assertForbidden();

        $this->assertNull($other->roleIn($workspace));
        $this->assertSame(WorkspaceRole::Editor, $editor->roleIn($workspace));
        $this->assertSame(WorkspaceRole::Viewer, $viewer->roleIn($workspace));
        $this->assertModelExists($invitation);
    }

    public function test_cannot_change_a_role_in_another_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $outsider = User::factory()->memberOf($other, WorkspaceRole::Viewer)->create();

        Livewire::actingAs($admin)
            ->test('pages::workspaces.members', ['workspace' => $workspace])
            ->call('changeRole', $outsider->id, 'admin')
            ->assertNotFound();

        $this->assertSame(WorkspaceRole::Viewer, $outsider->roleIn($other));
    }
}
