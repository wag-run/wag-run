<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MaskingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_masking_tab_shows_the_built_in_names_to_org_admins_only(): void
    {
        $this->actingAs(User::factory()->orgAdmin()->create())->get(route('admin.masking'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="built-in-rules"', __('admin.masking.built_in_summary'),
                'data-test="built-in-names"', 'Proxy-Authorization', 'client_secret', '*_token', __('admin.masking.names_exception'),
                'data-test="built-in-value_patterns"', 'X-Amz-Signature', __('admin.masking.value_patterns_exception'),
                'data-test="organization-names"'], false);

        $this->actingAs(User::factory()->orgMember()->create())->get(route('admin.masking'))->assertForbidden();
    }

    public function test_org_admin_adds_and_removes_names_which_save_right_away(): void
    {
        $page = Livewire::actingAs(User::factory()->orgAdmin()->create())
            ->test('pages::admin.masking')
            ->set('newSensitiveKey', 'X-Tenant-Key')->call('addSensitiveKey')
            ->set('newSensitiveKey', '*_license')->call('addSensitiveKey')
            ->assertSet('newSensitiveKey', '');

        $this->assertSame(['X-Tenant-Key', '*_license'], $this->organization()->sensitive_keys);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.settings_updated']);

        $page->set('newSensitiveKey', 'x_tenant_key')->call('addSensitiveKey')->assertHasErrors('newSensitiveKey')
            ->set('newSensitiveKey', 'no spaces/or (regex)')->call('addSensitiveKey')->assertHasErrors('newSensitiveKey')
            ->call('removeSensitiveKey', 0)
            ->assertSet('sensitiveKeys', ['*_license']);

        $this->assertSame(['*_license'], $this->organization()->sensitive_keys);
    }

    public function test_members_cannot_change_the_names(): void
    {
        User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->create();

        Livewire::actingAs($member)->test('pages::admin.masking')
            ->set('newSensitiveKey', 'X-Tenant-Key')->call('addSensitiveKey')->assertForbidden();

        $this->assertSame([], $this->organization()->sensitive_keys);
    }
}
