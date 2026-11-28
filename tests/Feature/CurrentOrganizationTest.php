<?php

namespace Tests\Feature;

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CurrentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->first = Organization::factory()->create(['name' => 'First']);
        $this->second = Organization::factory()->create(['name' => 'Second']);

        Route::middleware('web')->get('/__current-organization', fn () => Organization::current()->name ?? 'none');
    }

    private function member(Organization ...$organizations): User
    {
        $user = User::factory()->create();

        foreach ($organizations as $organization) {
            $user->organizations()->attach($organization, ['role' => OrgRole::Admin->value]);
        }

        return $user;
    }

    public function test_a_signed_in_user_gets_the_organization_they_belong_to_not_the_oldest(): void
    {
        $user = $this->member($this->second);

        $this->actingAs($user)->get('/__current-organization')->assertSee('Second');

        $this->assertSame($this->second->id, $user->refresh()->current_organization_id);
    }

    public function test_a_users_current_organization_is_kept_between_requests(): void
    {
        $user = $this->member($this->first, $this->second);
        $user->forceFill(['current_organization_id' => $this->second->id])->save();

        $this->actingAs($user)->get('/__current-organization')->assertSee('Second');
    }

    public function test_a_workspace_url_switches_to_the_workspaces_organization(): void
    {
        $user = $this->member($this->first, $this->second);
        $user->forceFill(['current_organization_id' => $this->first->id])->save();
        $workspace = Workspace::factory()->for($this->second)->create();

        $this->actingAs($user)->get(route('workspaces.show', $workspace))->assertOk();

        $this->assertSame($this->second->id, $user->refresh()->current_organization_id);
        $this->actingAs($user)->get('/__current-organization')->assertSee('Second');
    }

    public function test_a_workspace_in_an_organization_the_user_isnt_in_doesnt_switch_to_it(): void
    {
        $user = $this->member($this->first);
        $workspace = Workspace::factory()->for($this->second)->create();

        $this->actingAs($user)->get(route('workspaces.show', $workspace))->assertForbidden();

        $this->assertSame($this->first->id, $user->refresh()->current_organization_id);
    }

    public function test_an_organization_the_user_left_or_was_deactivated_in_is_replaced_by_an_active_one(): void
    {
        $user = $this->member($this->first, $this->second);
        $user->organizations()->updateExistingPivot($this->first->id, ['deactivated_at' => now()]);
        $user->forceFill(['current_organization_id' => $this->first->id])->save();

        $this->actingAs($user)->get('/__current-organization')->assertSee('Second');

        $this->assertSame($this->second->id, $user->refresh()->current_organization_id);
    }

    public function test_requests_without_a_user_still_fall_back_to_the_oldest_organization_for_now(): void
    {
        $this->get('/__current-organization')->assertSee('First');
    }
}
