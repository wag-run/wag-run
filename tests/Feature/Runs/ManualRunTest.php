<?php

namespace Tests\Feature\Runs;

use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ManualRunTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @return array{Workspace, Workflow, User}
     */
    private function setUpWorkflow(WorkspaceRole $role = WorkspaceRole::Editor): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, $role)->create();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.manual_trigger', ['fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true]]]],
                'say' => ['core.compose', ['template' => 'Refund {{ trigger.values.amount }}']]],
            ['start->say'],
        ), $workspace);

        return [$workspace, $workflow, $user];
    }

    public function test_run_asks_for_the_manual_triggers_inputs_then_opens_the_run(): void
    {
        [$workspace, $workflow, $user] = $this->setUpWorkflow();

        $component = Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->call('startRun')
            ->assertSet('runValues', ['amount' => null]);

        $this->assertSame(0, Execution::count());

        $component->set('runValues.amount', '120')->call('run');

        $execution = Execution::sole();
        $component->assertRedirect(route('workspaces.executions.show', [$workspace, $execution]));
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame($user->id, $execution->triggered_by);
        $this->assertSame(['values' => ['amount' => 120]], $execution->input);
        $this->assertSame(['value' => 'Refund 120'], $this->stepFor($execution, 'say')->output);
    }

    public function test_missing_required_inputs_are_reported_on_the_form(): void
    {
        [$workspace, $workflow, $user] = $this->setUpWorkflow();

        Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->call('startRun')
            ->call('run')
            ->assertHasErrors('runValues.amount');

        $this->assertSame(0, Execution::count());
    }

    public function test_a_workflow_without_inputs_runs_straight_away_from_its_first_trigger(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $workflow = $this->publishedWorkflow($this->graph(
            ['hook' => 'core.webhook_trigger', 'say' => ['core.compose', ['template' => 'hi']]],
            ['hook->say'],
        ), $workspace);

        Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->call('startRun')
            ->assertRedirect();

        $execution = Execution::sole();
        $this->assertSame('hook', $execution->trigger_node_id);
        $this->assertSame('manual', $execution->trigger_type);
    }

    public function test_viewers_cannot_run_and_unpublished_workflows_cannot_be_run(): void
    {
        [$workspace, $workflow] = $this->setUpWorkflow();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        Livewire::actingAs($viewer)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->assertDontSeeHtml('data-test="run-button"')
            ->call('startRun')
            ->assertForbidden();

        Livewire::actingAs($viewer)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->call('run')
            ->assertForbidden();

        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $draft = Workflow::factory()->for($workspace)->create();

        Livewire::actingAs($editor)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $draft])
            ->assertDontSeeHtml('data-test="run-button"')
            ->call('run')
            ->assertHasErrors('run');

        $this->assertSame(0, Execution::count());
    }
}
