<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class UpdateVariableTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * Run one Update variable node in a workflow that declares one variable.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values  the trigger's values
     * @param  array<string, int>|null  $retry
     */
    private function update(string $type, string $startingValue, array $config, array $values = [], ?array $retry = null): Execution
    {
        $workflow = $this->publishedWorkflow([
            ...$this->graph(['start' => 'core.manual_trigger', 'node' => ['core.variable_update', ['variable' => 'it', ...$config], $retry]], ['start->node']),
            'variables' => [['name' => 'it', 'type' => $type, 'value' => $startingValue]],
        ], Workspace::factory()->create());

        return $this->runWorkflow($workflow, ['values' => $values]);
    }

    private function value(Execution $execution): mixed
    {
        return $execution->variables()->where('name', 'it')->sole()->decoded();
    }

    public function test_set_converts_the_value_to_the_variables_type_and_reports_before_and_after(): void
    {
        $execution = $this->update('number', '5', ['operation' => 'set', 'value' => '{{ trigger.values.limit }}'], ['limit' => '42']);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['variable' => 'it', 'operation' => 'set', 'before' => 5, 'after' => 42], $this->stepFor($execution, 'node')->output);
        $this->assertSame(42, $this->value($execution));
    }

    public function test_set_keeps_an_object_from_a_placeholder_or_from_json_text(): void
    {
        $this->assertSame(['id' => 7], $this->value($this->update('object', '', ['value' => '{{ trigger.values.order }}'], ['order' => ['id' => 7]])));
        $this->assertSame(['id' => 8], $this->value($this->update('object', '', ['value' => '{"id": 8}'])));
    }

    public function test_append_adds_to_text_and_adds_a_whole_array_as_one_item(): void
    {
        $this->assertSame('a,b', $this->value($this->update('string', 'a', ['operation' => 'append', 'value' => ',b'])));
        $this->assertSame([[1, 2], [3, 4]], $this->value($this->update('array', '[[1, 2]]', ['operation' => 'append', 'value' => '{{ trigger.values.batch }}'], ['batch' => [3, 4]])));
    }

    public function test_increment_adds_the_amount_and_defaults_to_one(): void
    {
        $this->assertSame(11, $this->value($this->update('number', '10', ['operation' => 'increment'])));
        $this->assertEquals(7.5, $this->value($this->update('number', '10', ['operation' => 'increment', 'amount' => '{{ trigger.values.by }}'], ['by' => -2.5])));
    }

    public function test_a_value_that_does_not_fit_the_type_fails_the_step_without_retrying_and_changes_nothing(): void
    {
        $execution = $this->update('number', '5', ['value' => 'lots'], retry: ['max_retries' => 3]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Expected Number, but got the text "lots".', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame(1, $execution->steps()->where('node_id', 'node')->count());
        $this->assertSame(5, $this->value($execution));
    }

    public function test_an_operation_that_does_not_fit_the_type_fails_the_step(): void
    {
        $execution = $this->update('boolean', 'true', ['operation' => 'increment']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame("Increment doesn't work on Boolean.", $this->stepFor($execution, 'node')->error['message']);
    }

    public function test_a_value_over_the_size_limit_fails_the_step_including_one_that_grew_by_appending(): void
    {
        config(['workflows.variables.max_value_bytes' => 20]);

        $execution = $this->update('string', '0123456789', ['operation' => 'append', 'value' => '0123456789']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('The value is larger than 0 KB.', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame('0123456789', $this->value($execution));
    }

    public function test_a_variable_the_runs_version_does_not_declare_fails_the_step(): void
    {
        $execution = $this->update('string', '', ['variable' => 'gone', 'value' => 'x']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('This workflow has no variable "gone". Add it under Variables in the workflow settings.', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame(['it'], $execution->variables()->pluck('name')->all());
    }

    public function test_a_failed_update_can_be_caught_on_the_error_path(): void
    {
        $workflow = $this->publishedWorkflow([
            ...$this->graph(
                ['start' => 'core.manual_trigger', 'node' => ['core.variable_update', ['variable' => 'it', 'value' => 'lots']], 'caught' => ['core.compose', ['template' => '{{ error.message }}']]],
                ['start->node', 'node:error->caught'],
            ),
            'variables' => [['name' => 'it', 'type' => 'number', 'value' => '']],
        ], Workspace::factory()->create());

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame('Expected Number, but got the text "lots".', $this->stepFor($execution, 'caught')->output['value']);
    }
}
