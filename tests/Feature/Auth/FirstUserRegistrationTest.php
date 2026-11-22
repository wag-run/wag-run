<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstUserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function registration(string $email): array
    {
        return ['name' => 'Ada Lovelace', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'];
    }

    public function test_first_user_sets_up_the_organization_as_org_admin_with_a_default_workspace(): void
    {
        $this->post(route('register.store'), $this->registration('ada@example.com'))->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'ada@example.com');
        $organization = Organization::current();

        $this->assertNotNull($organization);
        $this->assertSame(OrgRole::Admin, $user->orgRole());
        $this->assertSame(['Default'], $organization->workspaces()->pluck('name')->all());
        $this->assertSame($organization->workspaces()->first()->id, $user->current_workspace_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.created', 'user_id' => $user->id]);
    }

    public function test_rejects_later_registrations_while_registration_is_by_invitation_only(): void
    {
        Organization::factory()->create(['registration_open' => false]);

        $this->post(route('register.store'), $this->registration('late@example.com'))
            ->assertSessionHasErrors(['email' => 'Registration is by invitation only. Ask an admin to invite you.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }

    public function test_registration_page_explains_invitation_only_when_closed(): void
    {
        Organization::factory()->create(['registration_open' => false]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Registration is by invitation only')
            ->assertDontSee('data-test="register-user-button"', false);
    }

    public function test_open_registration_creates_an_org_member_without_workspace_access(): void
    {
        $organization = Organization::factory()->create(['registration_open' => true]);
        $organization->workspaces()->create(['name' => 'Ops']);

        $this->post(route('register.store'), $this->registration('member@example.com'))->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'member@example.com');

        $this->assertSame(OrgRole::Member, $user->orgRole());
        $this->assertTrue($user->accessibleWorkspaces()->isEmpty());
    }
}
