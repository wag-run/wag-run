<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Enums\ValueType;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\StoreEntry;
use App\Models\StoreValue;
use App\Models\Workspace;
use App\Services\DataStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class UpdateDataStoreTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    private function entry(string $key, ValueType $type, mixed $value = null): StoreEntry
    {
        return StoreEntry::factory()->for($this->workspace)->type($type)->holding($value ?? $type->emptyValue())->create(['key' => $key]);
    }

    private function collection(string $key, ValueType $type, array $items = []): StoreEntry
    {
        return StoreEntry::factory()->for($this->workspace)->type($type)->collection()->holding($items)->create(['key' => $key]);
    }

    /**
     * Run one Update data store node, started by a manual trigger.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $values  the trigger's values
     */
    private function update(array $config, array $values = []): Execution
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'node' => ['core.store_update', $config]], ['start->node']), $this->workspace);

        return $this->runWorkflow($workflow, ['values' => $values]);
    }

    private function read(string $key, ?string $itemKey = null): mixed
    {
        $store = app(DataStore::class);

        return $store->read($store->entry($this->workspace, $key), $itemKey);
    }

    public function test_set_replaces_the_value_converting_it_to_the_entrys_type_and_reports_before_and_after(): void
    {
        $this->entry('threshold', ValueType::Number, 5);

        $execution = $this->update(['entry' => 'threshold', 'operation' => 'set', 'value' => '{{ trigger.values.limit }}'], ['limit' => '42']);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(
            ['entry' => 'threshold', 'item_key' => null, 'operation' => 'set', 'before' => 5, 'after' => 42],
            $this->stepFor($execution, 'node')->output,
        );
        $this->assertSame(42, $this->read('threshold'));
    }

    public function test_set_keeps_an_object_from_a_placeholder_or_from_json_text(): void
    {
        $this->entry('last_order', ValueType::Object);

        $this->update(['entry' => 'last_order', 'value' => '{{ trigger.values.order }}'], ['order' => ['id' => 7, 'lines' => [1, 2]]]);
        $this->assertSame(['id' => 7, 'lines' => [1, 2]], $this->read('last_order'));

        $this->update(['entry' => 'last_order', 'value' => '{"id": 8}']);
        $this->assertSame(['id' => 8], $this->read('last_order'));
    }

    public function test_append_adds_to_text_and_adds_a_whole_array_as_one_item(): void
    {
        $this->entry('log', ValueType::String, 'a');
        $this->entry('batches', ValueType::Array, [[1, 2]]);

        $this->update(['entry' => 'log', 'operation' => 'append', 'value' => ',b']);
        $this->update(['entry' => 'batches', 'operation' => 'append', 'value' => '{{ trigger.values.batch }}'], ['batch' => [3, 4]]);

        $this->assertSame('a,b', $this->read('log'));
        $this->assertSame([[1, 2], [3, 4]], $this->read('batches'));
    }

    public function test_increment_adds_the_amount_and_defaults_to_one(): void
    {
        $this->entry('runs', ValueType::Number, 10);

        $this->update(['entry' => 'runs', 'operation' => 'increment']);
        $execution = $this->update(['entry' => 'runs', 'operation' => 'increment', 'amount' => '{{ trigger.values.by }}'], ['by' => -2.5]);

        $this->assertSame(['before' => 11, 'after' => 8.5], array_intersect_key($this->stepFor($execution, 'node')->output, ['before' => 0, 'after' => 0]));
        $this->assertSame(8.5, $this->read('runs'));
    }

    public function test_a_collection_keeps_one_value_per_item_key_and_a_new_item_starts_from_empty(): void
    {
        $this->collection('failures', ValueType::Number, ['acme' => 2]);

        $new = $this->update(['entry' => 'failures', 'item_key' => '{{ trigger.values.customer }}', 'operation' => 'increment'], ['customer' => 'globex']);
        $this->update(['entry' => 'failures', 'item_key' => 'acme', 'operation' => 'increment', 'amount' => 3]);

        $this->assertSame(
            ['entry' => 'failures', 'item_key' => 'globex', 'operation' => 'increment', 'before' => null, 'after' => 1],
            $this->stepFor($new, 'node')->output,
        );
        $this->assertSame(['acme' => 5, 'globex' => 1], $this->read('failures'));
        $this->assertNull($this->read('failures', 'initech'));
    }

    public function test_a_collection_needs_an_item_key(): void
    {
        $this->collection('seen', ValueType::Boolean);

        $execution = $this->update(['entry' => 'seen', 'item_key' => '{{ trigger.values.missing }}', 'value' => 'true']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Item key is required for a collection.', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame(0, StoreValue::count());
    }

    public function test_a_value_that_does_not_fit_the_type_fails_the_step_without_retrying_and_changes_nothing(): void
    {
        $this->entry('threshold', ValueType::Number, 5);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'node' => ['core.store_update', ['entry' => 'threshold', 'value' => 'lots'], ['max_retries' => 3]]],
            ['start->node'],
        ), $this->workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Expected Number, but got the text "lots".', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame(1, $execution->steps()->where('node_id', 'node')->count());
        $this->assertSame(5, $this->read('threshold'));
    }

    public function test_an_operation_that_does_not_fit_the_type_fails_the_step(): void
    {
        $this->entry('enabled', ValueType::Boolean, true);

        $execution = $this->update(['entry' => 'enabled', 'operation' => 'increment']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame("Increment doesn't work on Boolean.", $this->stepFor($execution, 'node')->error['message']);
    }

    public function test_a_write_to_an_entry_that_does_not_exist_fails_and_never_creates_it(): void
    {
        $execution = $this->update(['entry' => 'cursor', 'value' => 'abc']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('There is no data store entry "cursor". Add it on the Data store page.', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame(0, StoreEntry::count());
    }

    public function test_workflows_can_read_a_read_only_entry_but_not_change_it(): void
    {
        StoreEntry::factory()->for($this->workspace)->readOnly()->holding('https://api.example.com')->create(['key' => 'api_base_url']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'read' => ['core.compose', ['template' => '{{ store.api_base_url }}/orders']], 'write' => ['core.store_update', ['entry' => 'api_base_url', 'value' => 'https://evil.example.com']]],
            ['start->read', 'read->write'],
        ), $this->workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('https://api.example.com/orders', $this->stepFor($execution, 'read')->output['value']);
        $this->assertSame('Data store entry "api_base_url" is read-only, so workflows can\'t change it.', $this->stepFor($execution, 'write')->error['message']);
        $this->assertSame('https://api.example.com', $this->read('api_base_url'));
    }

    public function test_a_later_step_reads_what_an_earlier_step_of_the_same_run_wrote(): void
    {
        $this->entry('cursor', ValueType::String, 'old');
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'before' => ['core.compose', ['template' => '{{ store.cursor }}']],
                'write' => ['core.store_update', ['entry' => 'cursor', 'value' => 'new']],
                'after' => ['core.compose', ['template' => '{{ store.cursor }}']]],
            ['start->before', 'before->write', 'write->after'],
        ), $this->workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('old', $this->stepFor($execution, 'before')->output['value']);
        $this->assertSame('new', $this->stepFor($execution, 'after')->output['value']);
    }

    public function test_placeholders_read_one_item_a_whole_collection_and_empty_for_what_is_not_there(): void
    {
        $this->collection('seen_tickets', ValueType::Boolean, ['T-1' => true, 'T-2' => true]);
        $read = fn (string $template, array $values = []) => $this->stepFor($this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'read' => ['core.compose', ['template' => $template]]],
            ['start->read'],
        ), $this->workspace), ['values' => $values]), 'read')->output['value'];

        $this->assertTrue($read('{{ store.seen_tickets[trigger.values.id] }}', ['id' => 'T-2']));
        $this->assertSame('empty', $read('{{ store.seen_tickets[trigger.values.id] === null ? "empty" : "set" }}', ['id' => 'T-9']));
        $this->assertSame(['T-1' => true, 'T-2' => true], $read('{{ store.seen_tickets }}'));
        $this->assertSame(2, $read('{{ length(store.seen_tickets) }}'));
        $this->assertSame(['T-1', 'T-2'], $read('{{ keys(store.seen_tickets) }}'));
        $this->assertSame('empty', $read('{{ store.no_such_entry === null ? "empty" : "set" }}'));
        $this->assertSame('empty', $read('{{ store.no_such_entry["x"].deeper === null ? "empty" : "set" }}'));
        $this->assertSame('seen: yes', $read('seen: {{ store.seen_tickets["T-1"] ? "yes" : "no" }}'));
    }

    public function test_a_value_over_the_size_limit_fails_the_step_including_one_that_grew_by_appending(): void
    {
        config(['workflows.store.max_value_bytes' => 20]);
        $this->entry('log', ValueType::String, '0123456789');

        $execution = $this->update(['entry' => 'log', 'operation' => 'append', 'value' => '0123456789']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('The value is larger than 0 KB.', $this->stepFor($execution, 'node')->error['message']);
        $this->assertSame('0123456789', $this->read('log'));
    }

    public function test_a_new_collection_item_fails_once_the_workspace_holds_as_many_values_as_it_may(): void
    {
        config(['workflows.store.max_values' => 3]);
        $this->entry('cursor', ValueType::String);
        $this->collection('counts', ValueType::Number, ['a' => 1, 'b' => 1]);
        StoreEntry::factory()->type(ValueType::Number)->collection()->holding(['x' => 1, 'y' => 1])->create(['key' => 'another_workspace']);

        $full = $this->update(['entry' => 'counts', 'item_key' => 'c', 'operation' => 'increment']);
        $existing = $this->update(['entry' => 'counts', 'item_key' => 'a', 'operation' => 'increment']);

        $this->assertSame(ExecutionStatus::Failed, $full->status);
        $this->assertSame('The data store is full: a workspace can hold 3 values. Delete entries or items on the Data store page.', $this->stepFor($full, 'node')->error['message']);
        $this->assertSame(['a' => 2, 'b' => 1], $this->read('counts'));
        $this->assertSame(ExecutionStatus::Complete, $existing->status);
    }

    public function test_a_write_by_a_workflow_is_not_in_the_audit_log(): void
    {
        $this->entry('cursor', ValueType::String);

        $this->update(['entry' => 'cursor', 'value' => 'abc']);

        $this->assertSame(0, AuditLog::count());
    }
}
