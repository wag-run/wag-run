<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstallSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_admin_opens_registration_and_sets_retention_to_forever(): void
    {
        $admin = User::factory()->installAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->set('registrationOpen', true)
            ->set('keepForever', true)
            ->call('save')
            ->assertHasNoErrors();

        $organization = Organization::current();
        $this->assertTrue($organization->registration_open);
        $this->assertNull($organization->default_retention_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'install.settings_updated']);
    }

    public function test_requires_a_retention_period_unless_kept_forever(): void
    {
        $admin = User::factory()->installAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.settings')
            ->set('keepForever', false)
            ->set('defaultRetentionDays', '')
            ->call('save')
            ->assertHasErrors(['defaultRetentionDays' => 'required']);

        $this->assertSame(30, Organization::current()->default_retention_days);
    }
}
