<?php

namespace Tests\Feature\Invitations;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Invitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AcceptInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Invitation, 1: string}
     */
    private function invite(string $email, ?Workspace $workspace = null, OrgRole $orgRole = OrgRole::Member): array
    {
        Notification::fake();
        $inviter = User::factory()->orgAdmin()->create();
        $workspace ??= Workspace::factory()->create();

        return app(Invitations::class)->invite($workspace->organization, $email, $inviter, $orgRole, $workspace, WorkspaceRole::Editor);
    }

    public function test_invitee_creates_an_account_and_joins_with_the_invited_role(): void
    {
        $workspace = Workspace::factory()->create();
        [, $token] = $this->invite('invitee@example.com', $workspace);

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Grace Hopper')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('dashboard');

        $user = User::firstWhere('email', 'invitee@example.com');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(OrgRole::Member, $user->orgRole());
        $this->assertSame(WorkspaceRole::Editor, $user->roleIn($workspace));
        $this->assertNotNull(Invitation::findByToken($token)->accepted_at);
    }

    public function test_an_invitation_creates_an_account_once_the_install_is_set_up(): void
    {
        [, $token] = $this->invite('closed@example.com');

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Closed Reg')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'closed@example.com']);
    }

    public function test_org_admin_invitation_makes_an_org_admin(): void
    {
        [, $token] = $this->invite('second-admin@example.com', orgRole: OrgRole::Admin);

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Second Admin')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $this->assertTrue(User::firstWhere('email', 'second-admin@example.com')->isOrgAdmin());
    }

    public function test_expired_invitation_is_unavailable(): void
    {
        [$invitation, $token] = $this->invite('late@example.com');
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('invitations.show', $token))->assertOk()->assertSee('Invitation unavailable');

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Too Late')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }

    public function test_signed_in_user_with_a_different_email_cannot_accept(): void
    {
        [$invitation, $token] = $this->invite('intended@example.com');
        $someoneElse = User::factory()->orgMember()->create();

        Livewire::actingAs($someoneElse)
            ->test('pages::invitations.show', ['token' => $token])
            ->call('accept')
            ->assertHasErrors(['invitation' => 'This invitation was sent to a different email address.']);

        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_unknown_token_shows_the_unavailable_page(): void
    {
        $this->get(route('invitations.show', 'not-a-real-token'))->assertOk()->assertSee('Invitation unavailable');
    }

    public function test_inviting_someone_already_in_the_organization_is_rejected(): void
    {
        User::factory()->orgMember()->create(['email' => 'exists@example.com']);

        $this->expectException(ValidationException::class);

        $this->invite('exists@example.com');
    }

    /**
     * Someone who signed up with their own organization, then is invited by
     * another one.
     */
    private function someoneInAnotherOrganization(): User
    {
        $own = Organization::factory()->create(['name' => 'Own']);
        $ownWorkspace = Workspace::factory()->for($own)->create();
        $user = User::factory()->memberOf($ownWorkspace, WorkspaceRole::Admin)->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        $user->forceFill(['current_organization_id' => $own->id, 'current_workspace_id' => $ownWorkspace->id])->save();

        return $user;
    }

    public function test_accepting_a_workspace_invitation_takes_someone_into_the_inviting_organization(): void
    {
        $inviting = Organization::factory()->create(['name' => 'Inviting']);
        $workspace = Workspace::factory()->for($inviting)->create();
        [, $token] = $this->invite('ada@example.com', $workspace);
        $user = $this->someoneInAnotherOrganization();

        Livewire::actingAs($user)
            ->test('pages::invitations.show', ['token' => $token])
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirectToRoute('dashboard');

        $user->refresh();
        $this->assertSame($inviting->id, $user->current_organization_id);
        $this->assertSame($workspace->id, $user->current_workspace_id);
        $this->assertSame(1, $user->workspaces()->where('organization_id', $inviting->id)->count());
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('workspaces.show', $workspace));
    }

    public function test_accepting_an_organization_invitation_gives_a_first_workspace_there(): void
    {
        Notification::fake();
        $inviting = Organization::factory()->create(['name' => 'Inviting']);
        $inviter = User::factory()->create();
        $inviter->organizations()->attach($inviting, ['role' => OrgRole::Admin->value]);
        [, $token] = app(Invitations::class)->invite($inviting, 'ada@example.com', $inviter);
        $user = $this->someoneInAnotherOrganization();

        Livewire::actingAs($user)
            ->test('pages::invitations.show', ['token' => $token])
            ->call('accept')
            ->assertHasNoErrors();

        $user->refresh();
        $workspace = $user->workspaces()->where('organization_id', $inviting->id)->sole();
        $this->assertSame("Ada's workspace", $workspace->name);
        $this->assertSame($inviting->id, $user->current_organization_id);
        $this->assertSame($workspace->id, $user->current_workspace_id);
    }

    public function test_accepting_into_an_organization_with_stricter_sign_in_rules_asks_for_them(): void
    {
        $inviting = Organization::factory()->create(['name' => 'Inviting', 'two_factor' => 'required']);
        $workspace = Workspace::factory()->for($inviting)->create();
        [, $token] = $this->invite('ada@example.com', $workspace);
        $user = $this->someoneInAnotherOrganization();

        $this->actingAs($user)->withSession(['auth.signed_in_with' => ['method' => 'password']]);
        Livewire::actingAs($user)->test('pages::invitations.show', ['token' => $token])->call('accept');

        $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    }

    public function test_tokens_are_stored_hashed(): void
    {
        [$invitation, $token] = $this->invite('hashed@example.com');

        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
    }
}
