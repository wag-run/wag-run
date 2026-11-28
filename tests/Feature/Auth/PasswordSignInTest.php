<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordSignInTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private OidcProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
        $this->provider = OidcProvider::factory()->for($this->organization)->create(['name' => 'Okta', 'slug' => 'okta']);
    }

    private function turnPasswordsOff(): void
    {
        $this->organization->update(['password_sign_in' => false]);
    }

    public function test_org_admins_turn_password_sign_in_off_once_a_provider_is_enabled(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSet('passwordSignIn', true)
            ->set('passwordSignIn', false)
            ->call('saveSignInMethods')
            ->assertHasNoErrors();

        $this->assertFalse($this->organization->refresh()->password_sign_in);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.settings_updated']);
    }

    public function test_password_sign_in_cant_be_turned_off_without_an_enabled_provider(): void
    {
        $this->provider->update(['enabled' => false]);
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->set('passwordSignIn', false)
            ->call('saveSignInMethods')
            ->assertHasErrors('passwordSignIn');

        $this->assertTrue($this->organization->refresh()->password_sign_in);
    }

    public function test_saving_a_provider_leaves_the_password_switch_as_saved(): void
    {
        $this->turnPasswordsOff();
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSet('passwordSignIn', false)
            ->call('edit', $this->provider->id)
            ->set('name', 'Okta SSO')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('passwordSignIn', false);
    }

    public function test_the_last_enabled_provider_cant_be_turned_off_or_deleted_while_passwords_are_off(): void
    {
        $this->turnPasswordsOff();
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('edit', $this->provider->id)
            ->set('enabled', false)
            ->call('save')
            ->assertHasErrors('enabled')
            ->call('delete', $this->provider->id);

        $this->assertTrue($this->provider->refresh()->enabled);
    }

    public function test_the_recovery_command_turns_password_sign_in_back_on(): void
    {
        $this->turnPasswordsOff();

        $this->artisan('sign-in:enable-password-login')->assertSuccessful();

        $this->assertTrue($this->organization->refresh()->password_sign_in);
    }

    public function test_the_sign_in_page_offers_no_password_when_the_organization_has_it_off(): void
    {
        OidcProvider::factory()->for($this->organization)->create(['name' => 'Entra ID', 'slug' => 'entra-id']);
        User::factory()->orgMember()->create(['email' => 'ada@example.com']);
        $this->turnPasswordsOff();

        $this->withSession(['login.email' => 'ada@example.com'])->get(route('login'))
            ->assertOk()
            ->assertSee('data-test="oidc-okta"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_the_sign_in_page_goes_straight_to_the_only_provider(): void
    {
        User::factory()->orgMember()->create(['email' => 'ada@example.com']);
        $this->turnPasswordsOff();

        $this->withSession(['login.email' => 'ada@example.com'])->get(route('login'))
            ->assertRedirect(route('oidc.redirect', $this->provider));

        $this->withSession(['login.email' => 'ada@example.com', 'status' => 'Something to say'])->get(route('login'))
            ->assertOk()
            ->assertSee('data-test="oidc-okta"', false);
    }

    public function test_an_unknown_email_still_gets_the_password(): void
    {
        $this->turnPasswordsOff();

        $this->withSession(['login.email' => 'nobody@elsewhere.example'])->get(route('login'))
            ->assertSee('name="password"', false);
    }

    public function test_a_correct_password_is_refused_when_the_organization_has_passwords_off(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->turnPasswordsOff();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => __('auth.login.your_organization_signs_in_with')]);

        $this->assertGuest();
    }

    public function test_a_password_works_while_any_of_the_persons_organizations_accepts_one(): void
    {
        $user = User::factory()->orgMember()->create();
        $user->organizations()->attach(Organization::factory()->create(), ['role' => OrgRole::Member->value]);
        $this->turnPasswordsOff();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_someone_signed_in_with_a_password_is_sent_to_sign_in_again_once_passwords_are_off(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->turnPasswordsOff();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('login.email', $user->email);

        $this->assertGuest();
    }

    public function test_someone_signed_in_through_the_organizations_provider_stays_signed_in(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->turnPasswordsOff();

        $this->actingAs($user)
            ->withSession(['auth.signed_in_with' => ['method' => 'oidc', 'provider_id' => $this->provider->id]])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_security_settings_and_reset_links_go_away_while_passwords_are_off(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->create();
        $this->turnPasswordsOff();
        $session = ['auth.signed_in_with' => ['method' => 'oidc', 'provider_id' => $this->provider->id]];

        $this->actingAs($admin)->withSession($session)->get(route('security.edit'))->assertNotFound();
        $this->actingAs($admin)->withSession($session)->get(route('profile.edit'))->assertDontSee(route('security.edit'));

        Livewire::actingAs($admin)->test('pages::admin.users')
            ->assertDontSee(__('admin.users.generate_password_reset_link'))
            ->call('generateResetLink', $member->id)
            ->assertNotFound();
    }
}
