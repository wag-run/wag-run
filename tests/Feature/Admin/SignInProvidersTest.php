<?php

namespace Tests\Feature\Admin;

use App\Models\OidcProvider;
use App\Models\Organization;
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

    public function test_org_admins_open_and_close_registration_from_the_sign_in_page(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        Organization::current()->update(['registration_open' => false]);

        $component = Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->assertSet('registrationOpen', false)
            ->set('registrationOpen', true);

        $this->assertTrue(Organization::current()->registration_open);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.settings_updated']);

        $component->set('registrationOpen', false);

        $this->assertFalse(Organization::current()->registration_open);
    }

    public function test_adding_a_provider_leaves_the_registration_switch_as_saved(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        Organization::current()->update(['registration_open' => true]);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('create')
            ->assertSet('registrationOpen', true)
            ->set('name', 'Acme ID')
            ->set('issuer', 'https://id.example.com')
            ->set('clientId', 'wag-run')
            ->set('clientSecret', 'shh-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('registrationOpen', true);

        $this->assertTrue(Organization::current()->registration_open);
    }

    public function test_members_cannot_change_registration(): void
    {
        $member = User::factory()->orgMember()->create();
        Organization::current()->update(['registration_open' => false]);

        Livewire::actingAs($member)->test('pages::admin.sign-in-providers')
            ->set('registrationOpen', true)
            ->assertForbidden();

        $this->assertFalse(Organization::current()->registration_open);
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
        $provider = OidcProvider::factory()->for(Organization::current())->create(['name' => 'Acme ID', 'slug' => 'acme-id', 'client_secret' => 'original']);

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
}
