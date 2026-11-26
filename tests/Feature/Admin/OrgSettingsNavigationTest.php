<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrgSettingsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admins_reach_org_settings_from_the_user_menu_instead_of_the_sidebar(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-test="org-settings-menu-item"', false)
            ->assertSee('href="'.route('admin.workspaces').'"', false)
            ->assertDontSee('href="'.route('admin.users').'"', false)
            ->assertSee('Preferences');
    }

    public function test_members_do_not_see_org_settings_in_the_user_menu(): void
    {
        $member = User::factory()->orgMember()->create();

        $this->actingAs($member)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-test="org-settings-menu-item"', false)
            ->assertSee('Preferences');
    }

    #[DataProvider('orgSettingsRoutes')]
    public function test_every_org_settings_page_shows_the_tabs_and_the_audit_log_link(string $routeName): void
    {
        $admin = User::factory()->orgAdmin()->create();

        $response = $this->actingAs($admin)->get(route($routeName))->assertOk();

        $response->assertSee('data-test="org-settings-tabs"', false);

        foreach (['admin.workspaces', 'admin.users', 'admin.sign-in-providers', 'admin.email', 'admin.settings', 'admin.audit'] as $tab) {
            $response->assertSee('href="'.route($tab).'"', false);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function orgSettingsRoutes(): array
    {
        return [
            'workspaces' => ['admin.workspaces'],
            'users' => ['admin.users'],
            'sign-in' => ['admin.sign-in-providers'],
            'email' => ['admin.email'],
            'general' => ['admin.settings'],
        ];
    }
}
