<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class ManageUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_admin_promotes_and_demotes_another_user(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->create();

        $page = Livewire::actingAs($admin)->test('pages::admin.users');

        $page->call('promote', $member->id);
        $this->assertTrue($member->isOrgAdmin());

        $page->call('demote', $member->id);
        $this->assertFalse($member->isOrgAdmin());

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.promoted', 'subject_id' => $member->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.demoted', 'subject_id' => $member->id]);
    }

    public function test_last_org_admin_cannot_be_demoted(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('demote', $admin->id)
            ->assertHasErrors(['user' => 'The last organization admin cannot be demoted.']);

        $this->assertTrue($admin->isOrgAdmin());
    }

    public function test_last_active_org_admin_cannot_be_deactivated(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $other = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('deactivate', $other->id)
            ->assertHasNoErrors()
            ->call('deactivate', $admin->id)
            ->assertHasErrors(['user']);

        $this->assertFalse($other->isOrgAdmin());

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('demote', $admin->id)
            ->assertHasErrors(['user' => 'The last organization admin cannot be demoted.']);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('deactivate', $admin->id)
            ->assertHasErrors(['user' => 'You cannot deactivate your own account.']);

        $this->assertFalse($admin->isDeactivated());
    }

    public function test_deactivation_ends_sessions_and_reactivation_restores_access(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->create();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $member->id, 'payload' => '', 'last_activity' => time()]);

        $page = Livewire::actingAs($admin)->test('pages::admin.users');

        $page->call('deactivate', $member->id);
        $this->assertTrue($member->isDeactivated());
        $this->assertDatabaseMissing('sessions', ['user_id' => $member->id]);

        $page->call('reactivate', $member->id);
        $this->assertFalse($member->isDeactivated());
    }

    public function test_generated_password_reset_link_carries_a_valid_token(): void
    {
        $admin = User::factory()->orgAdmin()->create();
        $member = User::factory()->orgMember()->create();

        $link = Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('generateResetLink', $member->id)
            ->get('resetLink');

        $token = basename(parse_url($link, PHP_URL_PATH));

        $this->assertTrue(Password::broker()->tokenExists($member, $token));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset_link_generated', 'subject_id' => $member->id]);
    }

    public function test_last_org_admin_cannot_delete_their_account(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.delete-user-modal')
            ->call('deleteUser')
            ->assertHasErrors(['user'])
            ->assertSee(__('settings.delete_user_modal.you_are_the_last_organization'));

        $this->assertModelExists($admin);
    }
}
