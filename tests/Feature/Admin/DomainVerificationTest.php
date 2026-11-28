<?php

namespace Tests\Feature\Admin;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Support\DnsTxtLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * On the hosted version, an organization proves it controls an email domain
 * with a DNS TXT record before the domain routes people or speaks for them.
 */
class DomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private OidcProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.self_hosted' => false]);
        $this->organization = $this->organization();
        $this->provider = OidcProvider::factory()->for($this->organization)->create(['name' => 'Okta', 'slug' => 'okta']);
    }

    /**
     * @param  list<string>  $records
     */
    private function dnsAnswers(array $records): void
    {
        $this->mock(DnsTxtLookup::class, fn (MockInterface $mock) => $mock->shouldReceive('records')->andReturn($records));
    }

    public function test_a_new_domain_is_pending_until_its_txt_record_is_found(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $domain = OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'acme.com']);
        $this->assertFalse($domain->isVerified());

        $this->dnsAnswers(['something-else']);
        $component = Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $this->provider->id)
            ->assertSee('Pending verification')
            ->call('toggleVerification', $domain->id)
            ->assertSee($domain->txtRecordName())
            ->assertSee($domain->txtRecordValue())
            ->call('checkDomain', $domain->id)
            ->assertHasErrors('verify');

        $this->assertFalse($domain->refresh()->isVerified());

        $this->dnsAnswers([$domain->txtRecordValue()]);
        $component->call('checkDomain', $domain->id)->assertHasNoErrors();

        $this->assertTrue($domain->refresh()->isVerified());
        $this->assertDatabaseHas('audit_logs', ['action' => 'oidc_provider.domain_verified', 'subject_label' => 'acme.com']);
    }

    public function test_only_one_organization_can_verify_a_domain(): void
    {
        $other = OidcProvider::factory()->for(Organization::factory()->create())->create();
        OidcDomain::factory()->for($other, 'provider')->create(['domain' => 'gmail.com', 'verified_at' => now()]);
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $this->provider->id)
            ->set('newDomain', 'gmail.com')
            ->call('addDomain')
            ->assertHasErrors(['newDomain' => 'Another organization has verified gmail.com.']);

        $this->assertFalse($this->provider->domains()->exists());
    }

    public function test_only_verified_domains_send_unknown_emails_to_a_provider(): void
    {
        $domain = OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'acme.com']);

        $this->withSession(['login.email' => 'new@acme.com'])->get(route('login'))
            ->assertDontSee('data-test="oidc-okta"', false)
            ->assertSee('name="password"', false);

        $domain->update(['verified_at' => now()]);

        $this->withSession(['login.email' => 'new@acme.com'])->get(route('login'))
            ->assertSee('data-test="oidc-okta"', false);
    }

    public function test_a_provider_with_only_pending_domains_creates_no_accounts(): void
    {
        $this->provider->update(['allow_registration' => true]);
        OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'acme.com']);

        $this->assertFalse($this->provider->createsAccounts());
        $this->assertFalse($this->provider->ownsEmail('ada@acme.com'));
    }

    public function test_self_hosted_installs_show_no_status_and_count_every_domain_verified(): void
    {
        config(['app.self_hosted' => true]);
        $domain = OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'acme.com']);
        $admin = User::factory()->orgAdmin()->create();

        $this->assertTrue($domain->isVerified());

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $this->provider->id)
            ->assertDontSee('Pending verification')
            ->assertDontSee('data-test="verify-acme.com"', false);
    }
}
