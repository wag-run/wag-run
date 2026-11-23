<?php

namespace Tests\Feature\Runs;

use App\Engine\Graph\WorkflowGraph;
use App\Enums\ConcurrencyPolicy;
use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ManualRuns;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class TestRunsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>  $graph
     * @return array{Workspace, Workflow, User}
     */
    private function draftWorkflow(array $graph, ?ConcurrencyPolicy $concurrency = null): array
    {
        $workspace = Workspace::factory()->create();
        $editor = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();
        $workflow = Workflow::factory()->for($workspace)->create(['draft_graph' => $graph, 'concurrency' => $concurrency ?? ConcurrencyPolicy::Allow]);
        app(Workflows::class)->saveDraft($workflow, $workflow->draft());

        return [$workspace, $workflow, $editor];
    }

    /**
     * @return array<string, mixed>
     */
    private function greetingGraph(string $greeting = 'Hello'): array
    {
        return $this->graph(
            ['start' => ['core.manual_trigger', ['fields' => [['name' => 'who', 'label' => 'Who', 'type' => 'text', 'required' => true]]]],
                'greet' => ['core.compose', ['template' => $greeting.' {{ trigger.values.who }}']]],
            ['start->greet'],
        );
    }

    public function test_a_test_run_runs_the_unpublished_draft_without_creating_a_version(): void
    {
        [, $workflow, $editor] = $this->draftWorkflow($this->greetingGraph());

        $execution = app(ManualRuns::class)->startTest($workflow, $editor, ['who' => 'Ada'])->refresh();

        $this->assertTrue($execution->is_test);
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame('Hello Ada', $this->stepFor($execution, 'greet')->output['value']);
        $this->assertTrue($execution->version->is_draft);
        $this->assertNull($execution->version->number);
        $this->assertSame(0, $workflow->versions()->count());
        $this->assertNull($workflow->refresh()->published_version_id);

        $this->assertSame(1, app(Workflows::class)->publish($workflow, $editor)->number, 'publishing numbers versions as if no test runs happened');
    }

    public function test_an_unchanged_draft_reuses_its_snapshot_and_an_edited_one_gets_a_new_one(): void
    {
        [, $workflow, $editor] = $this->draftWorkflow($this->greetingGraph());
        $runs = app(ManualRuns::class);

        $first = $runs->startTest($workflow, $editor, ['who' => 'Ada']);
        $second = $runs->startTest($workflow, $editor, ['who' => 'Grace']);
        app(Workflows::class)->saveDraft($workflow, WorkflowGraph::fromArray($this->greetingGraph('Hi')));
        $third = $runs->startTest($workflow->refresh(), $editor, ['who' => 'Linus'])->refresh();

        $this->assertSame($first->workflow_version_id, $second->workflow_version_id);
        $this->assertNotSame($first->workflow_version_id, $third->workflow_version_id);
        $this->assertSame('Hi Linus', $this->stepFor($third, 'greet')->output['value']);
    }

    public function test_a_draft_without_a_manual_trigger_receives_the_sample_data(): void
    {
        [, $workflow, $editor] = $this->draftWorkflow($this->graph(
            ['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'echo' => ['core.compose', ['template' => 'Order {{ trigger.body.id }}']]],
            ['hook->echo'],
        ));

        $execution = app(ManualRuns::class)->startTest($workflow, $editor, payload: '{"body": {"id": 42}}')->refresh();

        $this->assertSame('Order 42', $this->stepFor($execution, 'echo')->output['value']);
    }

    public function test_sample_data_must_be_a_json_object(): void
    {
        [, $workflow, $editor] = $this->draftWorkflow($this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]]));

        try {
            app(ManualRuns::class)->startTest($workflow, $editor, payload: '[1, 2]');
            $this->fail('A JSON list was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }

        $this->assertSame(0, Execution::count());
    }

    public function test_test_runs_neither_cancel_live_runs_nor_make_them_skip(): void
    {
        [, $cancelling, $editor] = $this->draftWorkflow($this->graph(['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['amount' => 1, 'unit' => 'hours']]], ['start->nap']), ConcurrencyPolicy::CancelPrevious);
        app(Workflows::class)->publish($cancelling, $editor);
        $live = $this->runWorkflow($cancelling->refresh());

        app(ManualRuns::class)->startTest($cancelling, $editor);
        $this->assertSame(ExecutionStatus::Waiting, $live->refresh()->status, 'a test run does not cancel the live run');

        [, $skipping, $editor] = $this->draftWorkflow($this->graph(['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['amount' => 1, 'unit' => 'hours']]], ['start->nap']), ConcurrencyPolicy::SkipIfRunning);
        app(Workflows::class)->publish($skipping, $editor);
        app(ManualRuns::class)->startTest($skipping->refresh(), $editor);

        $this->assertSame(ExecutionStatus::Waiting, $this->runWorkflow($skipping)->status, 'an in-progress test run does not make a live run skip');
    }

    public function test_the_editor_tests_the_draft_or_reports_why_it_cannot(): void
    {
        [$workspace, $workflow, $editor] = $this->draftWorkflow($this->graph(['start' => 'core.manual_trigger', 'fetch' => 'core.http_request'], ['start->fetch']));
        $page = Livewire::actingAs($editor)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->assertSeeHtml('data-test="test-draft"');

        $page->call('startTestRun')->assertSet('testErrors', ['Node "fetch" needs a value for "URL".']);
        $this->assertSame(0, Execution::count());

        $page->call('nodesChanged', ['type' => 'remove', 'nodes' => [['id' => 'fetch']]])->call('startTestRun');
        $execution = Execution::sole();
        $page->assertRedirect(route('workspaces.executions.show', [$workspace, $execution]));
        $this->assertTrue($execution->is_test);

        $this->actingAs($editor)->get(route('workspaces.executions.show', [$workspace, $execution]))->assertSee('Test run')->assertSee('Draft');
    }

    public function test_people_who_cannot_edit_cannot_test_the_draft(): void
    {
        [$workspace, $workflow] = $this->draftWorkflow($this->greetingGraph());
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        Livewire::actingAs($viewer)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow])
            ->assertDontSeeHtml('data-test="test-draft"')
            ->call('startTestRun')
            ->assertForbidden();
    }
}
