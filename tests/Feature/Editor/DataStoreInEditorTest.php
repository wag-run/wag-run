<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\ConfigForm;
use App\Engine\Editor\FieldFacts;
use App\Engine\Graph\StoreReferenceValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\ValueType;
use App\Enums\WorkspaceRole;
use App\Models\StoreEntry;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class DataStoreInEditorTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    /**
     * A draft with a manual trigger and one node.
     *
     * @param  array<string, mixed>  $config
     */
    private function draft(string $type, array $config): Workflow
    {
        return Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(['start' => 'core.manual_trigger', 'node' => [$type, $config]], ['start->node'])]);
    }

    private function editor(Workflow $workflow): Testable
    {
        $user = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();

        return Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $this->workspace, 'workflow' => $workflow]);
    }

    /**
     * @return list<string> what stops the draft from being published
     */
    private function publishErrors(Workflow $workflow): array
    {
        try {
            app(Workflows::class)->publish($workflow);
        } catch (ValidationException $e) {
            return $e->errors()['graph'];
        }

        return [];
    }

    public function test_finds_the_entries_placeholders_read_by_name_and_nothing_outside_placeholders(): void
    {
        $read = fn (mixed $config) => app(StoreReferenceValidator::class)->entriesRead($config);

        $this->assertSame(['cursor'], $read('Since {{ store.cursor }}'));
        $this->assertSame(['seen', 'limit'], $read(['a' => '{{ store.seen[trigger.id] ? 1 : store["limit"] }}', 'b' => ['{{ upper(store.seen["x"]) }}']]));
        $this->assertSame([], $read('https://store.example.com/{{ trigger.id }}'));
        $this->assertSame([], $read('{{ "store.quoted" }} {{ trigger.store.nested }} {{ nodes.store.output }}'));
        $this->assertSame([], $read('{{ store[trigger.name] }}'));
        $this->assertSame([], $read('{{ store.broken( }}'));
    }

    public function test_publishing_is_blocked_while_a_placeholder_reads_an_entry_that_does_not_exist(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'cursor']);
        $workflow = $this->draft('core.compose', ['template' => '{{ store.cursor }} {{ store.missing }}']);

        $this->assertSame(
            ['Node "node": {{ store.missing }} reads a data store entry that doesn\'t exist. Add it on the Data store page.'],
            $this->publishErrors($workflow),
        );

        StoreEntry::factory()->for($this->workspace)->create(['key' => 'missing']);

        $this->assertSame([], $this->publishErrors($workflow));
        $this->assertNotNull($workflow->refresh()->published_version_id);
    }

    public function test_an_entry_of_another_workspace_does_not_count(): void
    {
        StoreEntry::factory()->create(['key' => 'cursor']);

        $this->assertCount(1, $this->publishErrors($this->draft('core.compose', ['template' => '{{ store.cursor }}'])));
    }

    public function test_publishing_is_blocked_while_an_update_node_cannot_write_to_its_entry(): void
    {
        StoreEntry::factory()->for($this->workspace)->readOnly()->create(['key' => 'api_base_url']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Boolean)->create(['key' => 'enabled']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->collection()->create(['key' => 'failures']);

        $errors = fn (array $config) => $this->publishErrors($this->draft('core.store_update', $config));

        $this->assertSame(['Node "node": There is no data store entry "gone". Add it on the Data store page.'], $errors(['entry' => 'gone', 'value' => 'x']));
        $this->assertSame(['Node "node": Data store entry "api_base_url" is read-only, so workflows can\'t change it.'], $errors(['entry' => 'api_base_url', 'value' => 'x']));
        $this->assertSame(['Node "node": Increment doesn\'t work on Boolean.'], $errors(['entry' => 'enabled', 'operation' => 'increment']));
        $this->assertSame(['Node "node": Item key is required for a collection.'], $errors(['entry' => 'failures', 'operation' => 'increment']));
        $this->assertSame(['Node "node" needs a value for "Entry".'], $errors(['operation' => 'set', 'value' => 'x']));
        $this->assertSame([], $errors(['entry' => 'failures', 'item_key' => '{{ trigger.values.id }}', 'operation' => 'increment']));
    }

    public function test_a_test_run_of_the_draft_is_blocked_the_same_way(): void
    {
        $workflow = $this->draft('core.store_update', ['entry' => 'gone', 'value' => 'x']);

        $this->expectException(ValidationException::class);

        app(Workflows::class)->draftSnapshot($workflow);
    }

    public function test_the_editor_shows_a_selected_nodes_data_store_problems_and_publish_lists_them(): void
    {
        $workflow = $this->draft('core.compose', ['template' => '{{ store.missing }}']);

        $this->editor($workflow)
            ->call('selectNode', 'node')
            ->assertSeeHtml('data-test="node-problems"')
            ->assertSee('{{ store.missing }} reads a data store entry that doesn\'t exist.')
            ->call('selectNode', 'start')
            ->assertDontSeeHtml('data-test="node-problems"')
            ->call('publish')
            ->assertSet('publishErrors', ['Node "node": {{ store.missing }} reads a data store entry that doesn\'t exist. Add it on the Data store page.']);
    }

    public function test_the_update_node_offers_only_the_operations_that_fit_the_chosen_entrys_type(): void
    {
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->create(['key' => 'runs']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::String)->create(['key' => 'log']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Boolean)->create(['key' => 'enabled']);
        $node = app(NodeRegistry::class)->make('core.store_update');
        $entries = FieldFacts::storeEntries($this->workspace);
        $operation = collect($node->inputs())->firstWhere('name', 'operation');

        $offered = fn (?string $entry) => array_keys($operation->optionsFor(FieldFacts::for($node, ['entry' => $entry], $entries), $node->inputs()));

        $this->assertSame(['set', 'increment'], $offered('runs'));
        $this->assertSame(['set', 'append'], $offered('log'));
        $this->assertSame(['set'], $offered('enabled'));
        $this->assertSame(['set'], $offered(null));
        $this->assertSame(['set'], $offered('deleted_entry'));
    }

    public function test_choosing_an_entry_in_the_editor_resets_an_operation_that_no_longer_fits_and_asks_for_an_item_key_only_for_a_collection(): void
    {
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->create(['key' => 'runs']);
        StoreEntry::factory()->for($this->workspace)->type(ValueType::String)->collection()->create(['key' => 'notes']);
        $workflow = $this->draft('core.store_update', ['entry' => 'runs', 'operation' => 'increment', 'amount' => 2]);

        $editor = $this->editor($workflow)->call('selectNode', 'node')
            ->assertSeeHtml('wire:key="field-node-amount"')
            ->assertDontSeeHtml('wire:key="field-node-item_key"')
            ->assertSee('notes · String · Collection')->assertSee('runs · Number');

        $editor->set('form.entry', 'notes')
            ->assertSet('form.operation', 'set')
            ->assertSeeHtml('wire:key="field-node-item_key"')
            ->assertDontSeeHtml('wire:key="field-node-amount"')
            ->set('form.item_key', '{{ trigger.values.id }}')
            ->set('form.operation', 'append')
            ->set('form.value', 'hello');

        $this->assertSame(
            ['entry' => 'notes', 'item_key' => '{{ trigger.values.id }}', 'operation' => 'append', 'value' => 'hello'],
            WorkflowGraph::fromArray($workflow->refresh()->draft_graph)->node('node')->config,
        );
    }

    public function test_an_item_key_is_not_saved_for_a_single_entry(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'cursor']);
        $node = app(NodeRegistry::class)->make('core.store_update');
        $form = ['entry' => 'cursor', 'item_key' => 'left over', 'operation' => 'set', 'value' => 'x', 'amount' => ''];

        $config = ConfigForm::toConfig($node, $form, FieldFacts::for($node, $form, FieldFacts::storeEntries($this->workspace)));

        $this->assertSame(['entry' => 'cursor', 'operation' => 'set', 'value' => 'x'], $config);
    }

    public function test_a_node_whose_entry_was_deleted_still_shows_which_entry_it_used(): void
    {
        $workflow = $this->draft('core.store_update', ['entry' => 'gone', 'value' => 'x']);

        $this->editor($workflow)->call('selectNode', 'node')
            ->assertSee('gone (no longer exists)')
            ->assertSee('There is no data store entry "gone".');
    }
}
