<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The hosted version keeps sign-up open: each sign-up starts its own
 * organization.
 */
class HostedSignUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.self_hosted' => false]);
        Organization::factory()->create(['name' => 'Existing']);
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $email, string $organization = ''): array
    {
        return ['name' => 'Ada Lovelace', 'email' => $email, 'organization' => $organization, 'password' => 'password', 'password_confirmation' => 'password'];
    }

    public function test_sign_up_stays_open_once_organizations_exist(): void
    {
        $this->get(route('register'))->assertOk();
        $this->get(route('login'))->assertSee(route('register'));
    }

    public function test_signing_up_starts_an_organization_they_admin(): void
    {
        Notification::fake();

        $this->post(route('register.store'), $this->registration('ada@example.com', 'Analytical Engines'))->assertSessionHasNoErrors();

        $user = User::firstWhere('email', 'ada@example.com');
        $organization = $user->organizations()->sole();

        $this->assertSame('Analytical Engines', $organization->name);
        $this->assertSame(OrgRole::Admin, $user->orgRole());
        $this->assertSame(["Ada's workspace"], $organization->workspaces()->pluck('name')->all());
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_an_email_at_a_domain_an_organization_verified_is_sent_to_its_provider(): void
    {
        $provider = OidcProvider::factory()->for(Organization::factory()->create())->create(['name' => 'Acme SSO', 'slug' => 'acme-sso']);
        OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.com', 'verified_at' => now()]);

        $this->post(route('register.store'), $this->registration('Ada@Acme.com'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('login.email', 'ada@acme.com')
            ->assertSessionHas('status', 'People at acme.com sign in with Acme SSO. Continue below.');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ada@acme.com']);
        $this->get(route('login'))->assertSee('data-test="oidc-acme-sso"', false);
    }

    public function test_a_pending_domain_does_not_turn_sign_ups_away(): void
    {
        $provider = OidcProvider::factory()->for(Organization::factory()->create())->create();
        OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.com']);

        $this->post(route('register.store'), $this->registration('ada@acme.com'))->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_the_organization_name_is_limited_to_100_characters(): void
    {
        $this->post(route('register.store'), $this->registration('ada@example.com', str_repeat('a', 101)))
            ->assertSessionHasErrors('organization');

        $this->assertGuest();
    }
}
