<?php

namespace Tests\Feature\Admin;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SignInProvidersTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admins_add_a_provider_with_an_encrypted_secret(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('create')
            ->set('name', 'Acme ID')
            ->set('issuer', 'https://id.example.com/')
            ->set('clientId', 'wag-run')
            ->set('clientSecret', 'shh-secret')
            ->call('save')
            ->assertHasNoErrors();

        $provider = OidcProvider::sole();
        $this->assertSame('acme-id', $provider->slug);
        $this->assertSame('https://id.example.com', $provider->issuer);
        $this->assertSame('shh-secret', $provider->client_secret);
        $this->assertDatabaseMissing('oidc_providers', ['client_secret' => 'shh-secret']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oidc_provider.created', 'subject_id' => $provider->id]);
    }

    public function test_the_issuer_must_be_https(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->set('name', 'Plain')
            ->set('issuer', 'http://id.example.com')
            ->set('clientId', 'wag-run')
            ->set('clientSecret', 'shh')
            ->call('save')
            ->assertHasErrors('issuer');

        $this->assertSame(0, OidcProvider::count());
    }

    public function test_editing_keeps_the_secret_and_the_redirect_uri_when_left_alone(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create(['name' => 'Acme ID', 'slug' => 'acme-id', 'client_secret' => 'original']);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('edit', $provider->id)
            ->assertSet('clientSecret', '')
            ->set('name', 'Acme Identity')
            ->call('save')
            ->assertHasNoErrors();

        $provider->refresh();
        $this->assertSame('Acme Identity', $provider->name);
        $this->assertSame('acme-id', $provider->slug);
        $this->assertSame('original', $provider->client_secret);
    }

    public function test_members_cannot_manage_providers(): void
    {
        $member = User::factory()->orgMember()->create();

        $this->actingAs($member)->get(route('admin.sign-in-providers'))->assertForbidden();
    }

    public function test_org_admins_add_an_email_domain_from_the_providers_domains_dialog(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create(['name' => 'Acme ID']);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSeeHtml('data-test="domains-'.$provider->slug.'"')
            ->call('manageDomains', $provider->id)
            ->assertSee('No domains yet, so Acme ID accepts any email address.')
            ->set('addingDomain', true)
            ->set('newDomain', ' @Acme.COM ')
            ->call('addDomain')
            ->assertHasNoErrors()
            ->assertSet('addingDomain', false)
            ->assertSee('acme.com');

        $this->assertSame(['acme.com'], $provider->domains()->pluck('domain')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'oidc_provider.domain_added', 'subject_label' => 'acme.com']);
    }

    public function test_a_domain_belongs_to_one_provider_and_must_look_like_a_domain(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $okta = OidcProvider::factory()->for($this->organization())->create(['name' => 'Okta']);
        $entra = OidcProvider::factory()->for($this->organization())->create();
        OidcDomain::factory()->for($okta, 'provider')->create(['domain' => 'acme.com']);

        $component = Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $entra->id)
            ->set('newDomain', 'ACME.com')
            ->call('addDomain')
            ->assertHasErrors(['newDomain' => 'acme.com already belongs to Okta.']);

        $component->set('newDomain', 'not a domain')->call('addDomain')->assertHasErrors(['newDomain' => 'regex']);

        $this->assertSame(1, OidcDomain::count());
    }

    public function test_the_providers_table_shows_the_first_domain_and_how_many_more(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create();
        OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'beta.example']);
        OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'alpha.example']);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSeeInOrder(['alpha.example', '+1'])
            ->assertDontSee('beta.example');
    }

    public function test_org_admins_remove_a_domain(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create();
        $domain = OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.com']);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $provider->id)
            ->call('deleteDomain', $domain->id);

        $this->assertModelMissing($domain);
        $this->assertDatabaseHas('audit_logs', ['action' => 'oidc_provider.domain_deleted', 'subject_label' => 'acme.com']);
    }

    public function test_deleting_a_provider_deletes_its_domains(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create();
        $domain = OidcDomain::factory()->for($provider, 'provider')->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')->call('delete', $provider->id);

        $this->assertModelMissing($domain);
    }

    public function test_members_cannot_add_email_domains(): void
    {
        $member = User::factory()->orgMember()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create();

        Livewire::actingAs($member)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $provider->id)
            ->assertForbidden();

        $this->assertSame(0, OidcDomain::count());
    }

    public function test_creating_accounts_for_new_people_needs_an_email_domain(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->create();

        $component = Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('edit', $provider->id)
            ->set('allowRegistration', true)
            ->call('save')
            ->assertHasErrors('allowRegistration');

        $this->assertFalse($provider->refresh()->allow_registration);

        OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.com']);

        $component->call('edit', $provider->id)->set('allowRegistration', true)->call('save')->assertHasNoErrors();

        $this->assertTrue($provider->refresh()->allow_registration);
    }

    public function test_a_new_provider_cant_create_accounts_until_it_has_a_domain(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('create')
            ->set('name', 'Acme ID')
            ->set('issuer', 'https://id.example.com')
            ->set('clientId', 'wag-run')
            ->set('clientSecret', 'shh-secret')
            ->set('allowRegistration', true)
            ->call('save')
            ->assertHasErrors('allowRegistration');

        $this->assertSame(0, OidcProvider::count());
    }

    public function test_removing_a_providers_last_domain_stops_it_creating_accounts(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $provider = OidcProvider::factory()->for($this->organization())->allowingRegistration()->create();
        $first = OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.com']);
        $last = OidcDomain::factory()->for($provider, 'provider')->create(['domain' => 'acme.io']);

        $component = Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('manageDomains', $provider->id)
            ->call('deleteDomain', $first->id);

        $this->assertTrue($provider->refresh()->allow_registration);

        $component->call('deleteDomain', $last->id);

        $this->assertFalse($provider->refresh()->allow_registration);
    }
}
