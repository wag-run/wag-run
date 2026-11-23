<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrganizationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admin_opens_registration_and_sets_retention_to_forever(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->set('registrationOpen', true)
            ->set('keepForever', true)
            ->call('save')
            ->assertHasNoErrors();

        $organization = Organization::current();
        $this->assertTrue($organization->registration_open);
        $this->assertNull($organization->default_retention_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.settings_updated']);
    }

    public function test_the_page_is_called_organization_settings_and_listed_as_settings(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertOk()
            ->assertSeeInOrder(['<title>', 'Organization settings - '], false)
            ->assertDontSee('Sign-in &amp; defaults', false);
    }

    public function test_requires_a_retention_period_unless_kept_forever(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->set('keepForever', false)
            ->set('defaultRetentionDays', '')
            ->call('save')
            ->assertHasErrors(['defaultRetentionDays' => 'required']);

        $this->assertSame(30, Organization::current()->default_retention_days);
    }

    public function test_org_admin_sets_the_maximum_chain_depth(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->assertSet('maxChainDepth', 10)
            ->set('maxChainDepth', '0')
            ->call('save')
            ->assertHasErrors(['maxChainDepth' => 'min'])
            ->set('maxChainDepth', '4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(4, Organization::current()->max_chain_depth);
    }

    public function test_org_admin_sets_the_default_run_limit(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->assertSet('maxRunsPerMinute', 60)
            ->set('maxRunsPerMinute', '0')
            ->call('save')
            ->assertHasErrors(['maxRunsPerMinute' => 'min'])
            ->set('maxRunsPerMinute', '120')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(120, Organization::current()->max_runs_per_minute);
    }
}
