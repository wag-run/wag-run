<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_creates_a_variable_and_the_change_is_audited(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.variables', ['workspace' => $workspace])
            ->set('key', 'api_base_url')
            ->set('value', 'https://api.example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('variables', ['workspace_id' => $workspace->id, 'key' => 'api_base_url', 'value' => 'https://api.example.com']);
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $workspace->id, 'user_id' => $editor->id, 'action' => 'variable.created', 'subject_label' => 'api_base_url']);
    }

    public function test_viewer_cannot_change_variables(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        Livewire::actingAs($viewer)
            ->test('pages::workspaces.variables', ['workspace' => $workspace])
            ->set('key', 'sneaky')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('variables', 0);
    }

    public function test_rejects_keys_that_cannot_be_referenced(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.variables', ['workspace' => $workspace])
            ->set('key', '1st-key')
            ->call('save')
            ->assertHasErrors(['key' => 'Use letters, numbers and underscores, starting with a letter or underscore.']);
    }

    public function test_keys_are_unique_per_workspace_only(): void
    {
        $workspace = Workspace::factory()->create();
        Variable::factory()->for($workspace)->create(['key' => 'region']);
        Variable::factory()->for(Workspace::factory())->create(['key' => 'shared']);
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $page = Livewire::actingAs($editor)->test('pages::workspaces.variables', ['workspace' => $workspace]);

        $page->set('key', 'region')->call('save')->assertHasErrors(['key' => 'unique']);
        $page->set('key', 'shared')->call('save')->assertHasNoErrors();
    }

    public function test_cannot_edit_another_workspaces_variable(): void
    {
        $workspace = Workspace::factory()->create();
        $foreign = Variable::factory()->for(Workspace::factory())->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workspaces.variables', ['workspace' => $workspace])
            ->call('delete', $foreign->id)
            ->assertNotFound();

        $this->assertModelExists($foreign);
    }
}
