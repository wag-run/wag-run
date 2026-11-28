<?php

namespace Tests\Feature\Auth;

use App\Enums\OrgRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TwoFactorPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
    }

    private function policy(string $policy): void
    {
        $this->organization->update(['two_factor' => $policy]);
    }

    private function signInWithPassword(User $user): void
    {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    }

    public function test_org_admins_choose_the_policy_and_see_who_disabling_affects(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        User::factory()->orgMember()->withTwoFactor()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSet('twoFactor', 'optional')
            ->set('twoFactor', 'disabled')
            ->assertSee('1 person has it set up.')
            ->call('saveSignInMethods')
            ->assertHasNoErrors();

        $this->assertSame('disabled', $this->organization->refresh()->two_factor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.settings_updated']);
    }

    public function test_disabled_skips_the_code_but_keeps_the_setup(): void
    {
        $user = User::factory()->orgMember()->withTwoFactor()->create();
        $this->policy('disabled');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->refresh()->hasTwoFactorSetUp());
    }

    public function test_a_code_is_still_asked_for_while_any_of_the_persons_organizations_wants_one(): void
    {
        $user = User::factory()->orgMember()->withTwoFactor()->create();
        $user->organizations()->attach(Organization::factory()->create(), ['role' => OrgRole::Member->value]);
        $this->policy('disabled');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_disabled_hides_and_refuses_setup(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->policy('disabled');
        $this->actingAs($user);

        Livewire::test('pages::settings.security')->assertSet('canManageTwoFactor', false);
        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->call('startTwoFactorSetup')
            ->assertForbidden();
    }

    public function test_required_sends_someone_without_it_to_set_it_up(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->policy('required');
        $this->signInWithPassword($user);

        $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
        $this->get(route('security.edit'))
            ->assertOk()
            ->assertSee(__('settings.security.name_requires_two_factor_authentication', ['name' => $this->organization->name]));
        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest();
    }

    public function test_required_lets_people_with_it_through_but_not_turn_it_off(): void
    {
        $user = User::factory()->orgMember()->withTwoFactor()->create();
        $this->policy('required');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        Livewire::actingAs($user)->test('pages::settings.security')
            ->assertSet('twoFactorRequired', true)
            ->assertDontSee(__('settings.security.disable_2fa'))
            ->call('disable')
            ->assertForbidden();
    }

    public function test_required_leaves_provider_sign_ins_and_password_free_organizations_alone(): void
    {
        $user = User::factory()->orgMember()->create();
        $provider = OidcProvider::factory()->for($this->organization)->create();
        $this->policy('required');

        $this->actingAs($user)
            ->withSession(['auth.signed_in_with' => ['method' => 'oidc', 'provider_id' => $provider->id]])
            ->get(route('dashboard'))
            ->assertOk();

        $this->organization->update(['password_sign_in' => false]);

        $this->actingAs($user)
            ->withSession(['auth.signed_in_with' => ['method' => 'oidc', 'provider_id' => $provider->id]])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_org_admins_reset_someones_two_factor(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->withTwoFactor()->create();

        Livewire::actingAs($admin)->test('pages::admin.users')
            ->assertSee(__('admin.users.reset_two_factor'))
            ->call('resetTwoFactor', $member->id);

        $this->assertFalse($member->refresh()->hasTwoFactorSetUp());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.two_factor_reset', 'subject_id' => $member->id]);
    }
}
