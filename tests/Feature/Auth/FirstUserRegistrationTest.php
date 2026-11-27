<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_first_user_opens_the_app_without_confirming_their_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->registration('ada@example.com'));

        $user = User::firstWhere('email', 'ada@example.com');

        $this->assertTrue($user->hasVerifiedEmail());
        Notification::assertNothingSent();
        $this->get(route('workspaces.show', $user->accessibleWorkspaces()->sole()))->assertOk();
    }

    public function test_someone_who_registers_later_must_confirm_their_email(): void
    {
        Notification::fake();
        Organization::factory()->create(['registration_open' => true]);

        $this->post(route('register.store'), $this->registration('member@example.com'));

        $user = User::firstWhere('email', 'member@example.com');

        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
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

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'an email taken in another case' => [['email' => 'Taken@Example.com'], 'email', 'The email has already been taken.'],
            'a password that does not match its confirmation' => [['password_confirmation' => 'different'], 'password', 'The password field confirmation does not match.'],
            'a missing name' => [['name' => ''], 'name', 'The name field is required.'],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidRegistrations')]
    public function test_rejects_a_registration_that_fails_validation(array $input, string $field, string $message): void
    {
        Organization::factory()->create(['registration_open' => true]);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register.store'), [...$this->registration('new@example.com'), ...$input])
            ->assertSessionHasErrors([$field => $message]);

        $this->assertGuest();
        $this->assertSame(['taken@example.com'], User::pluck('email')->all());
    }
}
