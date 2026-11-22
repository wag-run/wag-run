<?php

namespace Tests\Feature\Invitations;

use App\Enums\InstallRole;
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
    private function invite(string $email, ?Workspace $workspace = null, InstallRole $installRole = InstallRole::Member): array
    {
        Notification::fake();
        $inviter = User::factory()->installAdmin()->create();
        $workspace ??= Workspace::factory()->create();

        return app(Invitations::class)->invite($workspace->organization, $email, $inviter, $installRole, $workspace, WorkspaceRole::Editor);
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
        $this->assertSame(InstallRole::Member, $user->installRole());
        $this->assertSame(WorkspaceRole::Editor, $user->roleIn($workspace));
        $this->assertNotNull(Invitation::findByToken($token)->accepted_at);
    }

    public function test_invitation_registration_works_while_open_registration_is_off(): void
    {
        [, $token] = $this->invite('closed@example.com');

        $this->assertFalse(Organization::current()->registration_open);

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Closed Reg')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'closed@example.com']);
    }

    public function test_install_admin_invitation_makes_an_install_admin(): void
    {
        [, $token] = $this->invite('second-admin@example.com', installRole: InstallRole::Admin);

        Livewire::test('pages::invitations.show', ['token' => $token])
            ->set('name', 'Second Admin')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $this->assertTrue(User::firstWhere('email', 'second-admin@example.com')->isInstallAdmin());
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
        $someoneElse = User::factory()->installMember()->create();

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

    public function test_inviting_an_existing_account_is_rejected(): void
    {
        User::factory()->installMember()->create(['email' => 'exists@example.com']);

        $this->expectException(ValidationException::class);

        $this->invite('exists@example.com');
    }

    public function test_tokens_are_stored_hashed(): void
    {
        [$invitation, $token] = $this->invite('hashed@example.com');

        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
    }
}
