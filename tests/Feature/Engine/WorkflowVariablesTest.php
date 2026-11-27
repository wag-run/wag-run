<?php

namespace Tests\Feature\Engine;

use App\Engine\Coordinator;
use App\Engine\Engine;
use App\Engine\NodeResult;
use App\Engine\Redactor;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\ValueOperation;
use App\Enums\ValueType;
use App\Models\Execution;
use App\Models\ExecutionVariable;
use App\Models\Secret;
use App\Models\StoreEntry;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WorkflowVariablesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
        $this->workspace = Workspace::factory()->create();
    }

    /**
     * A published workflow that declares variables, given as name => [type, starting value].
     *
     * @param  array<string, array{string, string}>  $variables
     * @param  array<string, mixed>  $nodes
     * @param  list<string>  $edges
     */
    private function workflow(array $variables, array $nodes = ['start' => 'start'], array $edges = []): Workflow
    {
        return $this->publishedWorkflow([
            ...$this->graph($nodes, $edges),
            'variables' => array_map(fn (string $name, array $variable) => ['name' => $name, 'type' => $variable[0], 'value' => $variable[1]], array_keys($variables), $variables),
        ], $this->workspace);
    }

    /**
     * @return array<string, mixed> the run's current values by variable name
     */
    private function values(Execution $execution): array
    {
        return $execution->variables()->orderBy('id')->get()->mapWithKeys(fn (ExecutionVariable $variable) => [$variable->name => $variable->decoded()])->all();
    }

    public function test_starting_values_are_worked_out_when_the_run_starts_and_converted_to_their_types(): void
    {
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->holding(5)->create(['key' => 'threshold']);
        $workflow = $this->workflow([
            'limit' => ['number', '{{ store.threshold * 2 }}'],
            'customer' => ['string', 'Customer {{ trigger.values.id }}'],
            'retries' => ['number', '3'],
            'tags' => ['array', '["new"]'],
            'run' => ['string', '{{ execution.workflow }}'],
        ]);

        $execution = $this->runWorkflow($workflow, ['values' => ['id' => 42]]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(
            ['limit' => 10, 'customer' => 'Customer 42', 'retries' => 3, 'tags' => ['new'], 'run' => $workflow->name],
            $this->values($execution),
        );
    }

    public function test_an_empty_starting_value_or_one_that_reads_nothing_is_the_types_empty_value(): void
    {
        $workflow = $this->workflow([
            'text' => ['string', ''],
            'count' => ['number', ''],
            'flag' => ['boolean', '{{ trigger.values.missing }}'],
            'data' => ['object', ''],
            'items' => ['array', '{{ trigger.values.missing }}'],
            'due' => ['datetime', ''],
        ]);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['text' => '', 'count' => 0, 'flag' => false, 'data' => [], 'items' => [], 'due' => null], $this->values($execution));
        $this->assertSame('{}', $execution->variables()->where('name', 'data')->sole()->value);
    }

    public function test_a_starting_value_that_does_not_fit_its_type_fails_the_run_before_its_first_step(): void
    {
        $workflow = $this->workflow(['limit' => ['number', '{{ trigger.values.limit }}']], ['start' => 'start', 'next' => 'emit'], ['start->next']);

        $execution = $this->runWorkflow($workflow, ['values' => ['limit' => 'lots']]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('The variable "limit" could not get its starting value. Expected Number, but got the text "lots".', $execution->error['message']);
        $this->assertSame(Engine::VARIABLE_COULD_NOT_START, $execution->error['code']);
        $this->assertNotNull($execution->finished_at);
        $this->assertSame(0, $execution->steps()->count());
        $this->assertSame(0, $execution->variables()->count());
    }

    public function test_a_workflow_without_variables_saves_none(): void
    {
        $execution = $this->runWorkflow($this->workflow([]));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(0, ExecutionVariable::count());
    }

    public function test_a_step_reads_what_an_earlier_step_of_the_run_wrote(): void
    {
        $workflow = $this->workflow(['status' => ['string', 'new']], [
            'start' => 'start',
            'before' => ['core.compose', ['template' => '{{ vars.status }}']],
            'write' => ['core.variable_update', ['variable' => 'status', 'value' => 'done']],
            'after' => ['core.compose', ['template' => '{{ vars.status }} and {{ vars.undeclared === null ? "nothing" : "something" }}']],
        ], ['start->before', 'before->write', 'write->after']);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('new', $this->stepFor($execution, 'before')->output['value']);
        $this->assertSame(['variable' => 'status', 'operation' => 'set', 'before' => 'new', 'after' => 'done'], $this->stepFor($execution, 'write')->output);
        $this->assertSame('done and nothing', $this->stepFor($execution, 'after')->output['value']);
    }

    public function test_a_value_changed_inside_a_loop_is_read_after_the_loop(): void
    {
        $workflow = $this->workflow(['total' => ['number', '0'], 'seen' => ['array', '']], [
            'start' => 'start',
            'each' => ['each', ['items' => [2, 3, 5]]],
            'add' => ['core.variable_update', ['variable' => 'total', 'operation' => 'increment', 'amount' => '{{ item }}']],
            'note' => ['core.variable_update', ['variable' => 'seen', 'operation' => 'append', 'value' => '{{ item }}']],
            'after' => ['core.compose', ['template' => '{{ vars.total }}']],
        ], ['start->each', 'each:item->add', 'add->note', 'each:done->after']);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(10, $this->stepFor($execution, 'after')->output['value']);
        $this->assertSame(['total' => 10, 'seen' => [2, 3, 5]], $this->values($execution));
    }

    public function test_append_and_increment_from_parallel_branches_both_apply(): void
    {
        $workflow = $this->workflow(['count' => ['number', '0'], 'log' => ['array', '']], [
            'start' => 'start', 'fork' => 'fork', 'left' => 'pause', 'right' => 'pause',
        ], ['start->fork', 'fork:a->left', 'fork:b->right']);
        $execution = $this->runWorkflow($workflow);
        $left = $this->stepFor($execution, 'left');
        $right = $this->stepFor($execution, 'right');

        // Both branches are mid-flight in their own workers, and both worked out their result
        // before either was applied, as two overlapping steps do.
        $left->forceFill(['status' => StepStatus::Running, 'claim_token' => 'worker-left'])->save();
        $right->forceFill(['status' => StepStatus::Running, 'claim_token' => 'worker-right'])->save();
        $fromLeft = NodeResult::updateVariable('count', ValueOperation::Increment, 1);
        $fromRight = NodeResult::updateVariable('count', ValueOperation::Increment, 2);

        app(Coordinator::class)->apply($left, 'worker-left', $fromLeft);
        app(Coordinator::class)->apply($right, 'worker-right', $fromRight);

        $this->assertSame(['before' => 0, 'after' => 1], array_intersect_key($left->refresh()->output, ['before' => 0, 'after' => 0]));
        $this->assertSame(['before' => 1, 'after' => 3], array_intersect_key($right->refresh()->output, ['before' => 0, 'after' => 0]));
        $this->assertSame(3, $this->values($execution)['count']);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_parallel_branches_run_through_real_nodes_keep_every_append_and_the_last_set(): void
    {
        $workflow = $this->workflow(['log' => ['array', ''], 'winner' => ['string', 'nobody']], [
            'start' => 'start', 'fork' => 'fork',
            'left' => ['core.variable_update', ['variable' => 'log', 'operation' => 'append', 'value' => 'left']],
            'right' => ['core.variable_update', ['variable' => 'log', 'operation' => 'append', 'value' => 'right']],
            'left_wins' => ['core.variable_update', ['variable' => 'winner', 'value' => 'left']],
            'right_wins' => ['core.variable_update', ['variable' => 'winner', 'value' => 'right']],
            'merge' => 'join',
            'end' => ['core.compose', ['template' => '{{ vars.log }}']],
        ], ['start->fork', 'fork:a->left', 'fork:b->right', 'left->left_wins', 'right->right_wins', 'left_wins->merge', 'right_wins->merge', 'merge->end']);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertEqualsCanonicalizing(['left', 'right'], $this->stepFor($execution, 'end')->output['value']);
        $lastToFinish = $execution->steps()->whereIn('node_id', ['left_wins', 'right_wins'])->orderByDesc('finished_at')->orderByDesc('id')->first();
        $this->assertSame($lastToFinish->output['after'], $this->values($execution)['winner']);
    }

    public function test_a_late_result_from_a_lost_worker_changes_nothing_and_the_retry_applies_once(): void
    {
        Queue::fake();
        $workflow = $this->workflow(['count' => ['number', '0']], [
            'start' => 'start',
            'add' => ['core.variable_update', ['variable' => 'count', 'operation' => 'increment'], ['max_retries' => 1, 'backoff_seconds' => 0]],
        ], ['start->add']);
        $execution = app(Engine::class)->start($workflow);
        $lost = $execution->steps()->create([
            'node_id' => 'add', 'node_type' => 'core.variable_update', 'status' => StepStatus::Running,
            'claim_token' => 'dead-worker', 'started_at' => now(), 'heartbeat_at' => now(),
        ]);

        app(Coordinator::class)->workerLost($lost);
        app(Coordinator::class)->apply($lost, 'dead-worker', NodeResult::updateVariable('count', ValueOperation::Increment, 1));

        $this->assertSame(StepStatus::Failed, $lost->refresh()->status);
        $this->assertSame(0, $this->values($execution)['count']);

        $retry = $this->stepFor($execution, 'add', attempt: 2);
        $retry->forceFill(['status' => StepStatus::Running, 'claim_token' => 'second-worker'])->save();
        app(Coordinator::class)->apply($retry, 'second-worker', NodeResult::updateVariable('count', ValueOperation::Increment, 1));
        app(Coordinator::class)->apply($retry, 'second-worker', NodeResult::updateVariable('count', ValueOperation::Increment, 1));

        $this->assertSame(1, $this->values($execution)['count']);
        $this->assertSame(['before' => 0, 'after' => 1], array_intersect_key($retry->refresh()->output, ['before' => 0, 'after' => 0]));
    }

    public function test_a_child_workflow_has_its_own_variables_and_does_not_see_the_callers(): void
    {
        $child = $this->workflow(['mine' => ['string', 'child']], [
            'start' => 'start',
            'read' => ['core.compose', ['template' => '{{ vars.mine }}/{{ vars.shared === null ? "unseen" : vars.shared }}']],
        ], ['start->read']);
        $parent = $this->workflow(['shared' => ['string', 'parent']], [
            'start' => 'start', 'call' => ['call', ['workflow_id' => $child->id]],
        ], ['start->call']);

        $execution = $this->runWorkflow($parent);

        $childRun = Execution::query()->where('workflow_id', $child->id)->sole();
        $this->assertSame('child/unseen', $this->stepFor($childRun, 'read')->output['value']);
        $this->assertSame(['shared' => 'parent'], $this->values($execution));
        $this->assertSame(['mine' => 'child'], $this->values($childRun));
    }

    public function test_secrets_are_masked_before_a_variables_value_is_saved(): void
    {
        Secret::factory()->for($this->workspace)->create(['key' => 'api_key', 'value' => 'sk_live_supersecret']);
        $workflow = $this->workflow(['token' => ['string', '']], [
            'start' => 'start',
            'leak' => ['core.variable_update', ['variable' => 'token', 'value' => 'Bearer {{ secrets.api_key }}']],
        ], ['start->leak']);

        $execution = $this->runWorkflow($workflow, ['values' => []]);

        $this->assertSame('Bearer '.Redactor::MASK, $this->values($execution)['token']);
        $this->assertStringNotContainsString('sk_live_supersecret', json_encode([$execution->variables()->get()->toArray(), $execution->steps()->get()->toArray()]));
    }
}
