<?php

namespace Tests\Feature\Runs;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ExecutionPagesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    private function branchingWorkflow(Workspace $workspace): Workflow
    {
        return $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'check' => ['route', ['take' => 'true']], 'yes' => 'emit', 'no' => 'emit'],
            ['start->check', 'check:true->yes', 'check:false->no'],
        ), $workspace);
    }

    public function test_the_run_list_shows_only_this_workspaces_runs_and_filters_by_workflow(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $first = $this->runWorkflow($this->branchingWorkflow($workspace));
        $second = $this->runWorkflow($this->branchingWorkflow($workspace));
        $foreign = $this->runWorkflow($this->branchingWorkflow(Workspace::factory()->create()));

        $ref = fn ($execution) => '#'.Execution::reference($execution->id);

        Livewire::actingAs($viewer)->test('pages::executions.index', ['workspace' => $workspace])
            ->assertSee([$ref($first), $ref($second)])
            ->assertDontSee($ref($foreign))
            ->set('workflow', $first->workflow_id)
            ->assertSee($ref($first))
            ->assertDontSee($ref($second));
    }

    public function test_a_run_shows_its_steps_and_the_nodes_it_never_reached(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $execution = $this->runWorkflow($this->branchingWorkflow($workspace));

        $component = Livewire::actingAs($viewer)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])
            ->assertSee('Complete')
            ->assertSeeHtml('data-test="not-run"')
            ->assertDontSeeHtml('data-test="cancel-run-button"');

        $this->assertSame(['no'], $component->get('notRun'));

        $statuses = collect($component->get('canvas')['nodes'])->mapWithKeys(fn (array $node) => [$node['id'] => $node['data']['status']])->all();
        $this->assertSame(['start' => 'complete', 'check' => 'complete', 'yes' => 'complete', 'no' => 'not_run'], $statuses);

        $component->call('selectNode', 'yes')
            ->assertSet('selectedStep', $this->stepFor($execution, 'yes')->id)
            ->assertSeeHtml('data-test="step-output"');
    }

    public function test_guests_cannot_open_runs_and_runs_of_other_workspaces_are_not_found(): void
    {
        $workspace = Workspace::factory()->create();
        $execution = $this->runWorkflow($this->branchingWorkflow($workspace));
        $guest = User::factory()->memberOf($workspace, WorkspaceRole::Guest)->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $foreign = $this->runWorkflow($this->branchingWorkflow(Workspace::factory()->create()));

        $this->actingAs($guest)->get(route('workspaces.executions', $workspace))->assertForbidden();
        $this->actingAs($guest)->get(route('workspaces.executions.show', [$workspace, $execution]))->assertForbidden();
        $this->actingAs($viewer)->get(route('workspaces.executions.show', [$workspace, $foreign]))->assertNotFound();
        $this->actingAs($viewer)->get(route('workspaces.executions.show', [$workspace, $execution]))->assertOk();
    }

    public function test_editors_can_cancel_a_run_in_progress_and_viewers_cannot(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(['start' => 'start', 'nap' => 'pause'], ['start->nap']), $workspace));
        $this->assertSame(ExecutionStatus::Waiting, $execution->status);

        Livewire::actingAs($viewer)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])
            ->assertDontSeeHtml('data-test="cancel-run-button"')
            ->call('cancel')
            ->assertForbidden();

        Livewire::actingAs($editor)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])
            ->assertSeeHtml('data-test="cancel-run-button"')
            ->call('cancel')
            ->assertSee('Cancelled by '.$editor->name);

        $execution->refresh();
        $this->assertSame(ExecutionStatus::Cancelled, $execution->status);
        $this->assertSame($editor->id, $execution->cancelled_by);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'nap')->status);
    }
}
