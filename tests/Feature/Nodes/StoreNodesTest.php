<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\StoreEntry;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class StoreNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    /**
     * Run one store node, started by a manual trigger, and return its step's output.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     */
    private function runNode(string $type, array $config, array $input = [], ?Workflow $workflow = null): Execution
    {
        $workflow ??= $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'node' => [$type, $config]], ['start->node']), $this->workspace);

        return $this->runWorkflow($workflow, $input);
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeOutput(Execution $execution): array
    {
        return $this->stepFor($execution, 'node')->output;
    }

    public function test_save_value_keeps_text_numbers_and_structures_for_later_runs(): void
    {
        $this->runNode('core.store_save', ['key' => 'plain', 'value' => 'hello']);
        $this->runNode('core.store_save', ['key' => 'order-{{ trigger.values.id }}', 'value' => '{{ trigger.values.order }}'], ['values' => ['id' => 7, 'order' => ['lines' => [1, 2], 'paid' => true]]]);
        $parsed = $this->runNode('core.store_save', ['key' => 'parsed', 'value' => '{"a": [1, 2]}', 'parse_json' => true]);

        $this->assertSame(['key' => 'parsed', 'value' => ['a' => [1, 2]]], $this->nodeOutput($parsed));

        $read = fn (string $key) => $this->nodeOutput($this->runNode('core.store_get', ['key' => $key]));
        $this->assertSame(['value' => 'hello', 'found' => true], $read('plain'));
        $this->assertSame(['value' => ['lines' => [1, 2], 'paid' => true], 'found' => true], $read('order-7'));
        $this->assertSame(['value' => ['a' => [1, 2]], 'found' => true], $read('parsed'));
    }

    public function test_saving_again_replaces_the_value_and_one_that_is_not_json_fails_when_asked_to_parse(): void
    {
        $this->runNode('core.store_save', ['key' => 'k', 'value' => 'first']);
        $this->runNode('core.store_save', ['key' => 'k', 'value' => 'second']);

        $this->assertSame('second', $this->nodeOutput($this->runNode('core.store_get', ['key' => 'k']))['value']);
        $this->assertSame(1, StoreEntry::count());

        $failed = $this->runNode('core.store_save', ['key' => 'k', 'value' => 'not json', 'parse_json' => true]);
        $this->assertSame(ExecutionStatus::Failed, $failed->status);
        $this->assertSame('second', $this->nodeOutput($this->runNode('core.store_get', ['key' => 'k']))['value']);
    }

    public function test_get_value_gives_the_default_when_nothing_is_saved(): void
    {
        $this->assertSame(['value' => '0', 'found' => false], $this->nodeOutput($this->runNode('core.store_get', ['key' => 'missing', 'default' => '0'])));
        $this->assertSame(['value' => null, 'found' => false], $this->nodeOutput($this->runNode('core.store_get', ['key' => 'missing'])));
    }

    public function test_a_saved_false_or_empty_value_is_found(): void
    {
        $this->runNode('core.store_save', ['key' => 'flag', 'value' => '{{ false }}']);

        $this->assertSame(['value' => false, 'found' => true], $this->nodeOutput($this->runNode('core.store_get', ['key' => 'flag', 'default' => 'x'])));
    }

    public function test_delete_value_removes_it_and_says_whether_there_was_one(): void
    {
        $this->runNode('core.store_save', ['key' => 'gone', 'value' => '1']);

        $this->assertSame(['deleted' => true], $this->nodeOutput($this->runNode('core.store_delete', ['key' => 'gone'])));
        $this->assertSame(['deleted' => false], $this->nodeOutput($this->runNode('core.store_delete', ['key' => 'gone'])));
        $this->assertFalse($this->nodeOutput($this->runNode('core.store_get', ['key' => 'gone']))['found']);
    }

    public function test_increment_counts_from_zero_by_the_amount_and_can_subtract(): void
    {
        $total = fn (array $config = []) => $this->nodeOutput($this->runNode('core.store_increment', ['key' => 'hits', ...$config]))['value'];

        $this->assertSame(1, $total());
        $this->assertSame(2, $total());
        $this->assertSame(7, $total(['by' => '5']));
        $this->assertSame(6.5, $total(['by' => '-0.5']));
        $this->assertSame(6.5, $this->nodeOutput($this->runNode('core.store_get', ['key' => 'hits']))['value']);
    }

    public function test_increment_refuses_a_value_that_is_not_a_number_or_an_amount_that_is_not(): void
    {
        $this->runNode('core.store_save', ['key' => 'name', 'value' => 'text']);

        $notNumber = $this->runNode('core.store_increment', ['key' => 'name']);
        $this->assertSame('The value saved under "name" is not a number.', $notNumber->error['message']);

        $badAmount = $this->runNode('core.store_increment', ['key' => 'n', 'by' => 'lots']);
        $this->assertSame('By must be a number.', $badAmount->error['message']);
    }

    public function test_seen_before_follows_new_once_then_seen(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'check' => ['core.store_seen_before', ['key' => 'ticket-{{ trigger.values.id }}']],
                'first' => ['core.compose', ['template' => 'first']], 'again' => ['core.compose', ['template' => 'again']]],
            ['start->check', 'check:new->first', 'check:seen->again'],
        ), $this->workspace);

        $one = $this->runWorkflow($workflow, ['values' => ['id' => 1]]);
        $this->assertSame(['start', 'check', 'first'], $this->nodesRun($one));
        $this->assertSame(['seen' => false], $this->stepFor($one, 'check')->output);

        $this->assertSame(['start', 'check', 'again'], $this->nodesRun($this->runWorkflow($workflow, ['values' => ['id' => 1]])));
        $this->assertSame(['start', 'check', 'first'], $this->nodesRun($this->runWorkflow($workflow, ['values' => ['id' => 2]])));
    }

    public function test_a_key_with_an_expiry_is_new_again_once_it_has_expired(): void
    {
        $seen = fn () => $this->nodeOutput($this->runNode('core.store_seen_before', ['key' => 'daily', 'expires_in' => 1, 'expires_unit' => 'days']))['seen'];

        $this->assertFalse($seen());
        $this->travel(23)->hours();
        $this->assertTrue($seen());
        $this->travel(2)->hours();
        $this->assertFalse($seen(), 'the expiry was set when the key was recorded, and not extended by checking it');
    }

    public function test_an_expired_value_reads_as_missing_and_saving_it_again_starts_a_new_expiry(): void
    {
        $this->runNode('core.store_save', ['key' => 'temp', 'value' => 'a', 'expires_in' => 30, 'expires_unit' => 'minutes']);
        $this->assertTrue($this->nodeOutput($this->runNode('core.store_get', ['key' => 'temp']))['found']);

        $this->travel(31)->minutes();
        $this->assertFalse($this->nodeOutput($this->runNode('core.store_get', ['key' => 'temp']))['found']);

        $this->runNode('core.store_save', ['key' => 'temp', 'value' => 'b', 'expires_in' => 1, 'expires_unit' => 'hours']);
        $this->travel(45)->minutes();
        $this->assertSame('b', $this->nodeOutput($this->runNode('core.store_get', ['key' => 'temp']))['value']);
        $this->assertSame(1, StoreEntry::count());
    }

    public function test_an_expired_counter_starts_over(): void
    {
        $total = fn () => $this->nodeOutput($this->runNode('core.store_increment', ['key' => 'today', 'expires_in' => 1, 'expires_unit' => 'hours']))['value'];

        $this->assertSame(1, $total());
        $this->assertSame(2, $total());
        $this->travel(61)->minutes();
        $this->assertSame(1, $total());
    }

    public function test_values_are_separate_between_workspaces(): void
    {
        $this->runNode('core.store_save', ['key' => 'shared', 'value' => 'mine']);

        $other = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'node' => ['core.store_get', ['key' => 'shared']]], ['start->node']));

        $this->assertSame(['value' => null, 'found' => false], $this->nodeOutput($this->runWorkflow($other)));
    }

    public function test_a_value_for_this_workflow_only_is_hidden_from_other_workflows(): void
    {
        $writer = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'save' => ['core.store_save', ['key' => 'cursor', 'value' => 'private', 'scope' => 'workflow']], 'node' => ['core.store_get', ['key' => 'cursor', 'scope' => 'workflow']]],
            ['start->save', 'save->node'],
        ), $this->workspace);
        $reader = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'node' => ['core.store_get', ['key' => 'cursor', 'scope' => 'workflow']]], ['start->node']), $this->workspace);

        $this->assertSame(['value' => 'private', 'found' => true], $this->nodeOutput($this->runWorkflow($writer)));
        $this->runNode('core.store_save', ['key' => 'cursor', 'value' => 'shared']);

        $this->assertSame(2, StoreEntry::count(), 'the same key can hold a shared value and one per workflow');
        $this->assertSame('shared', $this->nodeOutput($this->runNode('core.store_get', ['key' => 'cursor']))['value']);
        $this->assertFalse($this->nodeOutput($this->runWorkflow($reader))['found']);
        $this->assertSame('private', $this->nodeOutput($this->runWorkflow($writer))['value']);
    }

    public function test_an_empty_or_overlong_key_fails_the_step_without_retrying(): void
    {
        $empty = $this->runNode('core.store_get', ['key' => '  ']);
        $this->assertSame('Key is required.', $empty->error['message']);
        $this->assertSame(1, $empty->steps()->where('node_id', 'node')->count());

        $long = $this->runNode('core.store_get', ['key' => str_repeat('k', 192)]);
        $this->assertSame('Key can be at most 191 characters.', $long->error['message']);
    }

    public function test_a_value_over_the_size_limit_is_refused(): void
    {
        config(['workflows.store.max_value_bytes' => 2048]);

        $execution = $this->runNode('core.store_save', ['key' => 'big', 'value' => str_repeat('x', 3000)]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('The value is larger than 2 KB.', $execution->error['message']);
        $this->assertSame(0, StoreEntry::count());
    }

    public function test_a_full_store_refuses_new_keys_but_not_changes_to_existing_ones(): void
    {
        config(['workflows.store.max_entries' => 2]);

        $this->runNode('core.store_save', ['key' => 'a', 'value' => '1']);
        $this->runNode('core.store_save', ['key' => 'b', 'value' => '1']);

        $this->assertSame('The data store is full: a workspace can hold 2 values. Delete values or let them expire.', $this->runNode('core.store_save', ['key' => 'c', 'value' => '1'])->error['message']);
        $this->assertSame(ExecutionStatus::Failed, $this->runNode('core.store_increment', ['key' => 'd'])->status);
        $this->assertSame(ExecutionStatus::Failed, $this->runNode('core.store_seen_before', ['key' => 'e'])->status);
        $this->assertSame(['a', 'b'], StoreEntry::query()->orderBy('key')->pluck('key')->all());

        $this->assertSame(ExecutionStatus::Complete, $this->runNode('core.store_save', ['key' => 'a', 'value' => '2'])->status);
        $this->runNode('core.store_delete', ['key' => 'b']);
        $this->assertSame(ExecutionStatus::Complete, $this->runNode('core.store_save', ['key' => 'c', 'value' => '1'])->status);
    }

    public function test_expired_values_do_not_count_toward_the_limit(): void
    {
        config(['workflows.store.max_entries' => 1]);

        $this->runNode('core.store_save', ['key' => 'old', 'value' => '1', 'expires_in' => 1, 'expires_unit' => 'minutes']);
        $this->travel(2)->minutes();

        $this->assertSame(ExecutionStatus::Complete, $this->runNode('core.store_save', ['key' => 'new', 'value' => '1'])->status);
    }

    public function test_pruning_removes_expired_values_and_those_of_deleted_workflows_only(): void
    {
        $workflow = Workflow::factory()->for($this->workspace)->create();
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'keep']);
        StoreEntry::factory()->for($this->workspace)->expired()->create(['key' => 'expired']);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'mine', 'namespace' => $workflow->id]);
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'orphan', 'namespace' => '0f1e2d3c-4b5a-4968-8776-655443322110']);

        $this->artisan('workflows:prune-store')->expectsOutputToContain('Removed 2 data store values.')->assertSuccessful();

        $this->assertSame(['keep', 'mine'], StoreEntry::query()->orderBy('key')->pluck('key')->all());
    }

    public function test_deleting_a_workspace_removes_its_values(): void
    {
        $this->runNode('core.store_save', ['key' => 'a', 'value' => '1']);

        $this->workspace->delete();

        $this->assertSame(0, StoreEntry::count());
    }
}
