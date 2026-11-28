<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
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

    public function test_first_user_sets_up_the_organization_as_org_admin_with_their_own_workspace(): void
    {
        $this->post(route('register.store'), $this->registration('ada@example.com'))->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'ada@example.com');
        $organization = $this->organization();

        $this->assertNotNull($organization);
        $this->assertSame(OrgRole::Admin, $user->orgRole());
        $this->assertSame(["Ada's workspace"], $organization->workspaces()->pluck('name')->all());
        $this->assertMatchesRegularExpression('/^adas-workspace-[a-z0-9]{6}$/', $organization->workspaces()->sole()->slug);
        $this->assertSame($organization->workspaces()->first()->id, $user->current_workspace_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.created', 'user_id' => $user->id]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function organizationNames(): array
    {
        return [
            'a name given' => ['Analytical Engines', 'Analytical Engines'],
            'a blank name' => ['  ', "Ada's organization"],
        ];
    }

    #[DataProvider('organizationNames')]
    public function test_the_organization_takes_the_name_given_or_is_named_after_them(string $given, string $expected): void
    {
        $this->post(route('register.store'), [...$this->registration('ada@example.com'), 'organization' => $given])->assertSessionHasNoErrors();

        $this->assertSame($expected, $this->organization()->name);
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

    public function test_once_the_install_is_set_up_registration_is_refused_before_anything_is_checked(): void
    {
        Organization::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register.store'), $this->registration('taken@example.com'))
            ->assertSessionHasErrors(['email' => 'Registration is by invitation only. Ask an admin to invite you.']);

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_the_sign_up_page_sends_people_to_sign_in_once_the_install_is_set_up(): void
    {
        $this->get(route('register'))->assertOk();
        $this->get(route('login'))->assertSee(route('register'));

        Organization::factory()->create();

        $this->get(route('register'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertDontSee(route('register'));
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
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post(route('register.store'), [...$this->registration('new@example.com'), ...$input])
            ->assertSessionHasErrors([$field => $message]);

        $this->assertGuest();
        $this->assertSame(['taken@example.com'], User::pluck('email')->all());
    }
}
