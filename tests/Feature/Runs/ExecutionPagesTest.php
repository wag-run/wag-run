<?php

namespace Tests\Feature\Runs;

use App\Engine\Editor\CanvasMapper;
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

    public function test_a_node_inside_a_loop_can_be_viewed_one_iteration_at_a_time(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'each' => ['core.for_loop', ['items' => '{{ trigger.body.names }}']], 'greet' => ['core.compose', ['template' => 'Hi {{ item }}']]],
            ['start->each', 'each:item->greet'],
        ), $workspace), ['body' => ['names' => ['Ada', 'Grace', 'Linus']]]);
        $greetings = $execution->steps()->where('node_id', 'greet')->orderBy('id')->get();

        $page = Livewire::actingAs($viewer)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])
            ->call('selectNode', 'greet')
            ->assertSet('selectedStep', $greetings[2]->id)
            ->assertSeeHtml('data-test="iteration-switcher"')
            ->assertSee('Iteration 3 of 3')
            ->assertSee('Hi Linus');

        $page->call('selectStep', $greetings[0]->id)->assertSee('Hi Ada')->assertDontSee('Hi Linus');
        $page->set('selectedStep', $greetings[1]->id)->assertSee('Hi Grace');

        $page->call('selectNode', 'start')->assertDontSeeHtml('data-test="iteration-switcher"');
    }

    public function test_each_attempt_of_a_retried_step_can_be_opened(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['flaky', ['fail_times' => 1], ['max_retries' => 2, 'backoff_seconds' => 0]]],
            ['start->call'],
        ), $workspace));
        [$first, $second] = $execution->steps()->where('node_id', 'call')->orderBy('id')->get();

        Livewire::actingAs($viewer)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])
            ->call('selectNode', 'call')
            ->assertSet('selectedStep', $second->id)
            ->assertSeeHtml('data-test="attempt-switcher"')
            ->assertDontSeeHtml('data-test="iteration-switcher"')
            ->call('selectStep', $first->id)
            ->assertSee('Failed attempt 1');
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

    public function test_a_dot_travels_along_the_connection_into_an_active_step_until_it_finishes(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(['start' => 'start', 'nap' => 'pause', 'after' => 'emit'], ['start->nap', 'nap->after']), $workspace));
        $this->assertSame(StepStatus::Waiting, $this->stepFor($execution, 'nap')->status);

        $page = Livewire::actingAs($editor)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution]);

        $edges = collect($page->instance()->canvas['edges'])->keyBy('id');
        $this->assertSame('dot', $edges['e0']['animated']);
        $this->assertFalse($edges['e1']['animated']);

        $this->stepFor($execution, 'nap')->update(['status' => StepStatus::Complete]);
        $execution->update(['status' => ExecutionStatus::Complete]);

        $page->call('refreshRun')
            ->assertDispatched('flow:update', targets: ['edges' => ['e0' => ['animated' => false]]]);
    }

    public function test_the_run_canvas_can_be_moved_around_but_its_nodes_cannot_be_dragged(): void
    {
        $workspace = Workspace::factory()->create();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $execution = $this->runWorkflow($this->branchingWorkflow($workspace));

        $page = Livewire::actingAs($viewer)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution]);

        $this->assertStringContainsString('flowCanvas(', $page->html());
        $this->assertDoesNotMatchRegularExpression('/interactive(\\\\u0022|&quot;|"):false/', $page->html());

        foreach ($page->instance()->canvas['nodes'] as $node) {
            $this->assertFalse($node['draggable']);
        }
    }

    public function test_the_run_page_and_the_editor_draw_connections_the_same_way(): void
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $workflow = $this->branchingWorkflow($workspace);
        $execution = $this->runWorkflow($workflow);

        $runEdges = Livewire::actingAs($editor)->test('pages::executions.show', ['workspace' => $workspace, 'execution' => $execution])->instance()->canvas['edges'];
        $editorEdges = Livewire::actingAs($editor)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])->instance()->canvas['edges'];

        $this->assertSame([CanvasMapper::EDGE_TYPE], array_values(array_unique([...array_column($runEdges, 'type'), ...array_column($editorEdges, 'type')])));
    }
}
