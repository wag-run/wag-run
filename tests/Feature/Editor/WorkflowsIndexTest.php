<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\WorkflowExport;
use App\Enums\WorkspaceRole;
use App\Models\Credential;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WorkflowsIndexTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    public function test_creating_a_workflow_starts_it_with_a_manual_trigger_and_opens_the_editor(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        $component = Livewire::actingAs($editor)
            ->test('pages::workflows.index', ['workspace' => $workspace])
            ->set('name', 'Onboarding')
            ->call('create');

        $workflow = Workflow::sole();
        $component->assertRedirect(route('workspaces.workflows.edit', [$workspace, $workflow]));
        $this->assertSame($editor->id, $workflow->owner_id);
        $this->assertSame(['manual'], array_keys($workflow->draft()->nodes));
        $this->assertNull($workflow->published_version_id);
    }

    public function test_viewers_cannot_create_import_or_delete_workflows(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $workflow = Workflow::factory()->for($workspace)->create();

        Livewire::actingAs($viewer)->test('pages::workflows.index', ['workspace' => $workspace])
            ->set('name', 'Nope')
            ->call('create')
            ->assertForbidden();

        Livewire::actingAs($viewer)->test('pages::workflows.index', ['workspace' => $workspace])
            ->set('import', UploadedFile::fake()->createWithContent('nope.workflow.json', json_encode(WorkflowExport::export($workflow))))
            ->call('importFile')
            ->assertForbidden();

        Livewire::actingAs($viewer)->test('pages::workflows.index', ['workspace' => $workspace])
            ->call('delete', $workflow->id)
            ->assertForbidden();

        $this->assertSame([$workflow->id], Workflow::pluck('id')->all());
    }

    public function test_export_and_import_round_trip_the_draft_and_settings(): void
    {
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('shop'), 'description' => 'shop']);
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $source = Workflow::factory()->for($workspace)->create([
            'name' => 'Nightly sync',
            'concurrency' => 'skip',
            'retention_days' => 14,
            'draft_graph' => [
                ...$this->graph(['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['url' => 'https://x.test', 'credential' => $this->settingId('shop')]]], ['start->call']),
                'variables' => [['name' => 'attempts', 'type' => 'number', 'value' => '{{ store.attempts }}']],
            ],
        ]);

        $response = $this->actingAs($editor)->get(route('workspaces.workflows.export', [$workspace, $source]));
        $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="nightly-sync.workflow.json"');
        $json = $response->getContent();
        $this->assertSame(WorkflowExport::FORMAT, json_decode($json, true)['format']);

        Livewire::actingAs($editor)
            ->test('pages::workflows.index', ['workspace' => $workspace])
            ->set('import', UploadedFile::fake()->createWithContent('nightly.workflow.json', $json))
            ->call('importFile')
            ->assertHasNoErrors();

        $imported = Workflow::latest('id')->first();
        $this->assertNotSame($source->id, $imported->id);
        $this->assertSame('Nightly sync', $imported->name);
        $this->assertSame('skip', $imported->concurrency->value);
        $this->assertSame(14, $imported->retention_days);
        $this->assertEquals($source->draft()->toArray(), $imported->draft()->toArray());
        $this->assertSame([['name' => 'attempts', 'type' => 'number', 'value' => '{{ store.attempts }}']], json_decode($json, true)['graph']['variables']);
        $this->assertSame('{{ store.attempts }}', $imported->draft()->variables['attempts']->value);
        $this->assertNull($imported->published_version_id, 'imports arrive as drafts');
        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.imported', 'subject_id' => $imported->id]);

        $elsewhere = Workspace::factory()->create();
        Livewire::actingAs(User::factory()->memberOf($elsewhere, WorkspaceRole::Editor)->create())
            ->test('pages::workflows.index', ['workspace' => $elsewhere])
            ->set('import', UploadedFile::fake()->createWithContent('nightly.workflow.json', $json))
            ->call('importFile');

        $this->assertSame(['url' => 'https://x.test'], $elsewhere->workflows()->sole()->draft()->node('call')->config, 'a setting from another workspace is left to choose again');
    }

    public function test_rejects_files_that_are_not_workflow_exports(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Livewire::actingAs($editor)
            ->test('pages::workflows.index', ['workspace' => $workspace])
            ->set('import', UploadedFile::fake()->createWithContent('x.json', '{"nodes": []}'))
            ->call('importFile')
            ->assertHasErrors(['file' => 'This is not a workflow export file.']);

        $this->assertSame(0, Workflow::count());
    }

    public function test_cannot_export_another_workspaces_workflow(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->actingAs($viewer)->get(route('workspaces.workflows.export', [$workspace, Workflow::factory()->create()]))->assertNotFound();
    }

    public function test_list_shows_status_and_trigger_names(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $this->publishedWorkflow($this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]]), $workspace)->update(['name' => 'Inbound orders']);

        $this->actingAs($viewer)->get(route('workspaces.workflows', $workspace))
            ->assertOk()
            ->assertSee('Inbound orders')
            ->assertSee('Published v1')
            ->assertSee('Webhook');
    }

    public function test_list_marks_disabled_workflows(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        Workflow::factory()->for($workspace)->create(['name' => 'Running fine']);
        $list = fn () => $this->actingAs($viewer)->get(route('workspaces.workflows', $workspace))->assertOk();

        $list()->assertDontSee('data-test="workflow-disabled"', false);

        Workflow::factory()->for($workspace)->disabled()->create(['name' => 'Switched off']);
        $list()->assertSee('data-test="workflow-disabled"', false)->assertSee(__('workflows.index.disabled'));
    }

    public function test_admins_delete_a_workflow_by_its_uuid(): void
    {
        $workspace = Workspace::factory()->create();
        $admin = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        $workflow = Workflow::factory()->for($workspace)->create(['name' => 'Old flow']);

        Livewire::actingAs($admin)->test('pages::workflows.index', ['workspace' => $workspace])
            ->assertSeeHtml("ok && \$wire.delete('{$workflow->id}')")
            ->assertSeeHtml('Delete workflow?')
            ->call('delete', $workflow->id);

        $this->assertModelMissing($workflow);
    }
}
