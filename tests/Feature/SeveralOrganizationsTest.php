<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Enums\WorkspaceRole;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Someone can be in more than one organization: they pick one when signing
 * in, and switch between them from the workspace switcher.
 */
class SeveralOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $acme;

    private Organization $beta;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->acme = Organization::factory()->create(['name' => 'Acme']);
        $this->beta = Organization::factory()->create(['name' => 'Beta']);
        $this->ada = User::factory()->create(['email' => 'ada@example.com']);
        $this->ada->organizations()->attach($this->acme, ['role' => OrgRole::Member->value]);
        $this->ada->organizations()->attach($this->beta, ['role' => OrgRole::Member->value]);
    }

    public function test_someone_in_several_organizations_picks_one_before_the_ways_in(): void
    {
        $this->withSession(['login.email' => 'ada@example.com'])->get(route('login'))
            ->assertSee('data-test="organization-picker"', false)
            ->assertSeeInOrder(['Acme', 'Beta'])
            ->assertDontSee('name="password"', false);
    }

    public function test_someone_in_one_active_organization_sees_no_picker(): void
    {
        $this->ada->organizations()->updateExistingPivot($this->beta->id, ['deactivated_at' => now()]);

        $this->withSession(['login.email' => 'ada@example.com'])->get(route('login'))
            ->assertDontSee('data-test="organization-picker"', false)
            ->assertDontSee('Beta')
            ->assertSee('name="password"', false);
    }

    public function test_the_chosen_organization_s_ways_in_are_shown(): void
    {
        $this->beta->update(['password_sign_in' => false]);
        OidcProvider::factory()->for($this->beta)->create(['name' => 'Beta SSO', 'slug' => 'beta-sso']);
        OidcProvider::factory()->for($this->beta)->create(['name' => 'Beta Okta', 'slug' => 'beta-okta']);

        $this->withSession(['login.email' => 'ada@example.com'])
            ->post(route('login.organization'), ['organization' => $this->beta->id])
            ->assertRedirect(route('login'))
            ->assertSessionHas('login.organization', $this->beta->id);

        $this->get(route('login'))
            ->assertDontSee('data-test="organization-picker"', false)
            ->assertSee('data-test="oidc-beta-sso"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_an_organization_the_email_is_not_active_in_cannot_be_chosen(): void
    {
        $this->ada->organizations()->updateExistingPivot($this->beta->id, ['deactivated_at' => now()]);
        $other = Organization::factory()->create();

        foreach ([$this->beta->id, $other->id] as $organizationId) {
            $this->withSession(['login.email' => 'ada@example.com'])
                ->post(route('login.organization'), ['organization' => $organizationId])
                ->assertNotFound();
        }

        $this->flushSession();
        $this->post(route('login.organization'), ['organization' => $this->acme->id])->assertNotFound();
    }

    public function test_signing_in_enters_the_chosen_organization(): void
    {
        $this->ada->forceFill(['current_organization_id' => $this->acme->id])->save();

        $this->withSession(['login.email' => 'ada@example.com', 'login.organization' => $this->beta->id])
            ->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertSessionMissing('login.organization');

        $this->assertAuthenticatedAs($this->ada);
        $this->assertSame($this->beta->id, $this->ada->refresh()->current_organization_id);
    }

    public function test_a_new_email_and_not_you_forget_the_chosen_organization(): void
    {
        $this->withSession(['login.email' => 'ada@example.com', 'login.organization' => $this->beta->id])
            ->post(route('login.email'), ['email' => 'ada@example.com'])
            ->assertSessionMissing('login.organization');

        $this->withSession(['login.email' => 'ada@example.com', 'login.organization' => $this->beta->id])
            ->delete(route('login.email.destroy'))
            ->assertSessionMissing(['login.email', 'login.organization']);
    }

    public function test_the_switcher_groups_workspaces_by_organization(): void
    {
        $acmeWorkspace = Workspace::factory()->for($this->acme)->create(['name' => 'Acme ops']);
        $betaWorkspace = Workspace::factory()->for($this->beta)->create(['name' => 'Beta ops']);
        Workspace::factory()->for($this->beta)->create(['name' => 'Beta hidden']);
        $this->ada->workspaces()->attach($acmeWorkspace, ['role' => WorkspaceRole::Editor->value]);
        $this->ada->workspaces()->attach($betaWorkspace, ['role' => WorkspaceRole::Editor->value]);

        $this->actingAs($this->ada)->get(route('workspaces.show', $acmeWorkspace))
            ->assertSee('data-test="switcher-organization-'.$this->acme->id.'"', false)
            ->assertSeeInOrder(['Acme', 'Acme ops', 'Beta', 'Beta ops'])
            ->assertSee(route('workspaces.show', $betaWorkspace))
            ->assertDontSee('Beta hidden');
    }

    public function test_opening_another_organization_s_workspace_switches_into_it(): void
    {
        $betaWorkspace = Workspace::factory()->for($this->beta)->create();
        $this->ada->workspaces()->attach($betaWorkspace, ['role' => WorkspaceRole::Editor->value]);
        $this->ada->forceFill(['current_organization_id' => $this->acme->id])->save();

        $this->actingAs($this->ada)->get(route('workspaces.show', $betaWorkspace))->assertOk();

        $this->assertSame($this->beta->id, $this->ada->refresh()->current_organization_id);
    }

    public function test_an_organization_without_workspaces_for_them_is_switched_to_directly(): void
    {
        $this->ada->forceFill(['current_organization_id' => $this->acme->id])->save();

        $this->actingAs($this->ada)->get(route('dashboard'))
            ->assertSee('data-test="switch-organization-'.$this->beta->id.'"', false);

        $this->actingAs($this->ada)->post(route('organizations.switch', $this->beta))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($this->beta->id, $this->ada->refresh()->current_organization_id);
    }

    public function test_the_last_workspace_used_in_another_organization_is_not_shown_as_open(): void
    {
        $acmeWorkspace = Workspace::factory()->for($this->acme)->create(['name' => 'Acme ops']);
        $this->ada->workspaces()->attach($acmeWorkspace, ['role' => WorkspaceRole::Editor->value]);
        $this->ada->forceFill(['current_organization_id' => $this->beta->id, 'current_workspace_id' => $acmeWorkspace->id])->save();

        $this->actingAs($this->ada)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('workspaces.workflows', $acmeWorkspace));
    }

    public function test_only_an_active_member_can_switch_to_an_organization(): void
    {
        $this->ada->organizations()->updateExistingPivot($this->beta->id, ['deactivated_at' => now()]);
        $other = Organization::factory()->create();
        $this->ada->forceFill(['current_organization_id' => $this->acme->id])->save();

        foreach ([$this->beta, $other] as $organization) {
            $this->actingAs($this->ada)->post(route('organizations.switch', $organization))->assertNotFound();
        }

        $this->assertSame($this->acme->id, $this->ada->refresh()->current_organization_id);
    }

    public function test_workspace_slugs_are_unique_across_organizations(): void
    {
        $acmeOps = Workspace::factory()->for($this->acme)->create(['name' => 'Ops']);
        $betaOps = Workspace::factory()->for($this->beta)->create(['name' => 'Ops']);

        $this->assertNotSame($acmeOps->slug, $betaOps->slug);

        $this->expectException(UniqueConstraintViolationException::class);
        Workspace::factory()->for($this->beta)->create(['slug' => $acmeOps->slug]);
    }
}
