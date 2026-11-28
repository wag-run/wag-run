<?php

namespace Tests\Feature;

use App\Engine\Redactor;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Assignments;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * With more than one organization, each piece of code works from the
 * organization its record belongs to, never just the first one.
 */
class MultipleOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Organization::factory()->create(['name' => 'First']);
        $this->second = Organization::factory()->create(['name' => 'Second']);
    }

    private function memberOf(Organization $organization, OrgRole $role = OrgRole::Member, bool $deactivated = false): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => $role->value, 'deactivated_at' => $deactivated ? now() : null]);

        return $user;
    }

    public function test_masking_uses_the_workspaces_own_organizations_names(): void
    {
        $this->second->update(['sensitive_keys' => ['X-Tenant-Key']]);
        $workspace = Workspace::factory()->for($this->second)->create();

        $masked = Redactor::forWorkspace($workspace)->mask(['X-Tenant-Key' => 'tenant-value']);

        $this->assertSame(Redactor::MASK, $masked['X-Tenant-Key']);
    }

    public function test_an_assignee_must_be_active_in_the_workspaces_organization(): void
    {
        $workspace = Workspace::factory()->for($this->second)->create();
        $active = $this->memberOf($this->second);
        $elsewhere = $this->memberOf($this->first);
        $deactivated = $this->memberOf($this->second, deactivated: true);
        $assignments = app(Assignments::class);

        $this->assertTrue($assignments->activeUser($active->id, $workspace)?->is($active));
        $this->assertNull($assignments->activeUser($elsewhere->id, $workspace));
        $this->assertNull($assignments->activeUser($deactivated->id, $workspace));
    }

    public function test_an_organization_admin_acts_as_admin_only_in_their_own_organizations_workspaces(): void
    {
        $admin = $this->memberOf($this->second, OrgRole::Admin);

        $this->assertNotNull($admin->roleIn(Workspace::factory()->for($this->second)->create()));
        $this->assertNull($admin->roleIn(Workspace::factory()->for($this->first)->create()));
    }

    public function test_someone_deactivated_in_one_organization_but_active_in_another_can_still_sign_in(): void
    {
        $user = $this->memberOf($this->first, deactivated: true);
        $user->organizations()->attach($this->second, ['role' => OrgRole::Member->value]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_someone_deactivated_everywhere_cant_sign_in(): void
    {
        $user = $this->memberOf($this->first, deactivated: true);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_providers_slug_is_unique_across_organizations(): void
    {
        OidcProvider::factory()->for($this->first)->create(['name' => 'Okta', 'slug' => 'okta']);
        $admin = $this->memberOf($this->second, OrgRole::Admin);

        Livewire::actingAs($admin)->test('pages::admin.sign-in-providers')
            ->call('create')
            ->set('name', 'Okta')
            ->set('issuer', 'https://id.example.com')
            ->set('clientId', 'wag-run')
            ->set('clientSecret', 'shh-secret')
            ->call('save')
            ->assertHasNoErrors();

        $slug = $this->second->oidcProviders()->sole()->slug;

        $this->assertNotSame('okta', $slug);
        $this->assertStringStartsWith('okta-', $slug);
    }

    public function test_an_organization_admin_sees_audit_entries_only_from_organizations_they_administer(): void
    {
        Audit::record('user.promoted', label: 'first_entry', organization: $this->first);
        Audit::record('user.promoted', label: 'second_entry', organization: $this->second);
        $admin = $this->memberOf($this->second, OrgRole::Admin);

        $this->assertSame(['second_entry'], AuditLog::visibleTo($admin)->pluck('subject_label')->all());
    }
}
