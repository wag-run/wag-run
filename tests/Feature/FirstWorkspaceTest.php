<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FirstWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->organization();
    }

    private function signIn(User $user): void
    {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('logout'));
    }

    public function test_someone_without_a_workspace_gets_their_own_at_first_sign_in(): void
    {
        $user = User::factory()->orgMember()->create(['name' => 'Ada Lovelace']);

        $this->signIn($user);

        $workspace = Workspace::query()->where('organization_id', $this->organization->id)->sole();
        $this->assertSame("Ada's workspace", $workspace->name);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $workspace->slug);
        $this->assertSame(WorkspaceRole::Admin, $user->roleIn($workspace));
        $this->assertSame($workspace->id, $user->refresh()->current_workspace_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'workspace.created', 'subject_id' => $workspace->id]);
    }

    public function test_someone_who_already_has_a_workspace_gets_none(): void
    {
        $workspace = Workspace::factory()->for($this->organization)->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $this->signIn($user);

        $this->assertSame(1, Workspace::count());
    }

    public function test_it_only_happens_once(): void
    {
        $user = User::factory()->orgMember()->create();
        $this->signIn($user);
        $user->workspaces()->detach();

        $this->signIn($user);

        $this->assertSame(1, Workspace::count());
    }

    public function test_organizations_can_turn_it_off_and_people_who_signed_in_meanwhile_dont_get_one_later(): void
    {
        $this->organization->update(['first_workspaces' => false]);
        $user = User::factory()->orgMember()->create();
        $this->signIn($user);

        $this->assertSame(0, Workspace::count());

        $this->organization->update(['first_workspaces' => true]);
        $this->signIn($user);

        $this->assertSame(0, Workspace::count());
    }

    public function test_org_admins_turn_it_off_in_general_settings(): void
    {
        $admin = User::factory()->orgAdmin()->create();

        Livewire::actingAs($admin)->test('pages::admin.settings')
            ->assertSet('firstWorkspaces', true)
            ->set('firstWorkspaces', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($this->organization->refresh()->first_workspaces);
    }

    public function test_a_name_made_from_an_email_gives_a_tidy_first_name(): void
    {
        $this->assertSame('Ada', User::factory()->make(['name' => 'ada.lovelace'])->firstName());
        $this->assertSame('James', User::factory()->make(['name' => 'James Watt'])->firstName());
        $this->assertSame('Ada', User::factory()->make(['name' => 'Ada'])->firstName());
    }
}
