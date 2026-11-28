<?php

namespace Tests\Feature\Auth;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailFirstSignInTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    public function test_the_first_step_asks_only_for_the_email(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-test="continue-button"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_the_email_is_kept_in_the_session_not_the_url(): void
    {
        $this->post(route('login.email'), ['email' => ' Ada@Example.COM '])
            ->assertRedirect(route('login'))
            ->assertSessionHas('login.email', 'ada@example.com');

        $this->get(route('login'))
            ->assertSee('ada@example.com')
            ->assertSee('data-test="login-button"', false)
            ->assertSee('data-test="not-you"', false);
    }

    public function test_the_email_must_look_like_one(): void
    {
        $this->post(route('login.email'), ['email' => 'not an email'])->assertSessionHasErrors('email');

        $this->assertNull(session('login.email'));
    }

    public function test_an_email_with_an_account_and_one_without_see_the_same_ways_in(): void
    {
        User::factory()->orgMember()->create(['email' => 'ada@example.com']);
        OidcProvider::factory()->for($this->organization)->create(['name' => 'Acme ID']);

        $known = $this->withSession(['login.email' => 'ada@example.com'])->get(route('login'))->getContent();
        $unknown = $this->withSession(['login.email' => 'bob@example.com'])->get(route('login'))->getContent();

        $this->assertSame(
            Str::betweenFirst((string) $known, '<body', '<script'),
            Str::betweenFirst(str_replace('bob@example.com', 'ada@example.com', (string) $unknown), '<body', '<script'),
        );
    }

    public function test_the_provider_claiming_the_email_comes_first_and_providers_for_other_domains_are_hidden(): void
    {
        $okta = OidcProvider::factory()->for($this->organization)->create(['name' => 'Okta', 'slug' => 'okta']);
        $entra = OidcProvider::factory()->for($this->organization)->create(['name' => 'Entra ID', 'slug' => 'entra-id']);
        $contractors = OidcProvider::factory()->for($this->organization)->create(['name' => 'Contractors', 'slug' => 'contractors']);
        OidcDomain::factory()->for($okta, 'provider')->create(['domain' => 'acme.com']);
        OidcDomain::factory()->for($contractors, 'provider')->create(['domain' => 'contractors.example']);

        $this->withSession(['login.email' => 'ada@acme.com'])->get(route('login'))
            ->assertSeeInOrder(['data-test="oidc-okta"', 'data-test="oidc-entra-id"'], false)
            ->assertDontSee('data-test="oidc-contractors"', false);

        $this->withSession(['login.email' => 'ada@elsewhere.example'])->get(route('login'))
            ->assertSee('data-test="oidc-entra-id"', false)
            ->assertDontSee('data-test="oidc-okta"', false)
            ->assertDontSee('data-test="oidc-contractors"', false);
    }

    public function test_not_you_goes_back_to_the_email_step(): void
    {
        $this->withSession(['login.email' => 'ada@example.com'])
            ->delete(route('login.email.destroy'))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('login.email');

        $this->get(route('login'))->assertSee('data-test="continue-button"', false);
    }

    public function test_a_wrong_password_is_reported_on_the_method_step(): void
    {
        $user = User::factory()->orgMember()->create(['email' => 'ada@example.com']);

        $this->withSession(['login.email' => $user->email])
            ->from(route('login'))
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertSee('ada@example.com')
            ->assertSee(__('auth.failed'));
        $this->assertGuest();
    }

    public function test_the_email_step_is_throttled(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->post(route('login.email'), ['email' => 'ada@example.com'])->assertSessionHasNoErrors();
        }

        $this->post(route('login.email'), ['email' => 'ada@example.com'])->assertSessionHasErrors('email');
    }
}
