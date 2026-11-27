<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\FieldFacts;
use App\Engine\Graph\PlaceholderReferences;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\ValueType;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\StoreEntry;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class VariablesInEditorTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
    }

    /**
     * A draft with a manual trigger, one node, and variables given as name => [type, starting value].
     *
     * @param  array<string, array{string, string}>  $variables
     * @param  array<string, mixed>  $config
     */
    private function draft(array $variables, string $type = 'core.compose', array $config = ['template' => 'x']): Workflow
    {
        return Workflow::factory()->for($this->workspace)->create(['draft_graph' => [
            ...$this->graph(['start' => 'core.manual_trigger', 'node' => [$type, $config]], ['start->node']),
            'variables' => array_map(fn (string $name, array $variable) => ['name' => $name, 'type' => $variable[0], 'value' => $variable[1]], array_keys($variables), $variables),
        ]]);
    }

    private function editor(Workflow $workflow, WorkspaceRole $role = WorkspaceRole::Editor): Testable
    {
        $user = User::factory()->memberOf($this->workspace, $role)->create();

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

    /**
     * @return array<string, array{type: string, value: string}> the draft's variables by name
     */
    private function declared(Workflow $workflow): array
    {
        return array_map(
            fn ($variable) => ['type' => $variable->type, 'value' => $variable->value],
            WorkflowGraph::fromArray($workflow->refresh()->draft_graph)->variables,
        );
    }

    public function test_finds_every_name_a_templates_placeholders_read(): void
    {
        $references = app(PlaceholderReferences::class);

        $this->assertSame(['trigger', 'store', 'vars'], $references->roots('{{ upper(trigger.values.name) }} {{ store.limit > vars.count ? "a" : "b" }}'));
        $this->assertSame([], $references->roots('plain text with store.example.com'));
        $this->assertSame(['count'], $references->under('vars', ['a' => '{{ vars.count + 1 }}', 'b' => 'vars.ignored']));
    }

    public function test_publishing_is_blocked_while_a_placeholder_reads_a_variable_that_is_not_declared(): void
    {
        $workflow = $this->draft(['count' => ['number', '0']], 'core.compose', ['template' => '{{ vars.count }} {{ vars.missing }}']);

        $this->assertSame(
            ['Node "node": {{ vars.missing }} reads a variable this workflow doesn\'t declare. Add it under Variables in the workflow settings.'],
            $this->publishErrors($workflow),
        );
    }

    public function test_publishing_is_blocked_while_an_update_node_cannot_change_its_variable(): void
    {
        $errors = fn (array $config) => $this->publishErrors($this->draft(['enabled' => ['boolean', 'true'], 'count' => ['number', '']], 'core.variable_update', $config));

        $this->assertSame(['Node "node": This workflow has no variable "gone". Add it under Variables in the workflow settings.'], $errors(['variable' => 'gone', 'value' => 'x']));
        $this->assertSame(['Node "node": Increment doesn\'t work on Boolean.'], $errors(['variable' => 'enabled', 'operation' => 'increment']));
        $this->assertSame(['Node "node" needs a value for "Variable".'], $errors(['operation' => 'set', 'value' => 'x']));
        $this->assertSame([], $errors(['variable' => 'count', 'operation' => 'increment']));
    }

    #[TestWith(['9lives', 'number', '0', 'Variable "9lives": Use letters, numbers, and underscores, starting with a letter or underscore.'])]
    #[TestWith(['total', 'money', '0', 'Variable "total": Its type "money" is not one this install knows.'])]
    #[TestWith(['total', 'number', 'lots', 'Variable "total": Expected Number, but got the text "lots".'])]
    #[TestWith(['total', 'number', '{{ vars.other }}', 'Variable "total": A starting value can read trigger, store, and execution, but not vars.'])]
    #[TestWith(['total', 'number', '{{ nodes.fetch.output.count }}', 'Variable "total": A starting value can read trigger, store, and execution, but not nodes.'])]
    #[TestWith(['total', 'string', '{{ secrets.api_key }}', 'Variable "total": A starting value can read trigger, store, and execution, but not secrets.'])]
    #[TestWith(['total', 'number', '{{ trigger.values.count', 'Variable "total": Its starting value has a placeholder that can\'t be read. Check its brackets and quotes.'])]
    #[TestWith(['total', 'number', '{{ store.missing }}', 'Variable "total": {{ store.missing }} reads a data store entry that doesn\'t exist. Add it on the Data store page.'])]
    public function test_publishing_is_blocked_by_a_declaration_that_could_not_start_a_run(string $name, string $type, string $value, string $error): void
    {
        $this->assertSame([$error], $this->publishErrors($this->draft([$name => [$type, $value]])));
    }

    public function test_a_starting_value_may_read_the_trigger_the_data_store_and_the_run(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'cursor']);

        $this->assertSame([], $this->publishErrors($this->draft([
            'since' => ['string', '{{ store.cursor }}'],
            'who' => ['string', '{{ trigger.values.name }} in {{ execution.workflow }}'],
        ])));
    }

    public function test_a_workflow_can_declare_only_so_many_variables(): void
    {
        config(['workflows.variables.max_count' => 2]);

        $this->assertSame(
            ['A workflow can declare at most 2 variables.'],
            $this->publishErrors($this->draft(['a' => ['string', ''], 'b' => ['string', ''], 'c' => ['string', '']])),
        );
    }

    public function test_a_test_run_of_the_draft_is_blocked_the_same_way(): void
    {
        $this->expectException(ValidationException::class);

        app(Workflows::class)->draftSnapshot($this->draft([], 'core.compose', ['template' => '{{ vars.missing }}']));
    }

    public function test_the_editor_shows_problems_on_the_node_and_in_the_variables_section(): void
    {
        $workflow = $this->draft(['total' => ['number', '{{ vars.other }}']], 'core.compose', ['template' => '{{ vars.missing }}']);

        $this->editor($workflow)
            ->assertSeeHtml('data-test="variable-problems"')
            ->assertSee('Variable "total": A starting value can read trigger, store, and execution, but not vars.')
            ->call('selectNode', 'node')
            ->assertSeeHtml('data-test="node-problems"')
            ->assertSee('{{ vars.missing }} reads a variable this workflow doesn\'t declare.')
            ->call('publish')
            ->assertSet('publishErrors', [
                'Variable "total": A starting value can read trigger, store, and execution, but not vars.',
                'Node "node": {{ vars.missing }} reads a variable this workflow doesn\'t declare. Add it under Variables in the workflow settings.',
            ]);
    }

    public function test_adding_editing_and_deleting_a_variable_saves_the_draft_and_can_be_undone(): void
    {
        $workflow = $this->draft([]);
        $editor = $this->editor($workflow);

        $editor->call('addVariable')
            ->set('variableForm.name', 'attempts')->set('variableForm.type', 'number')->set('variableForm.value', '3')
            ->call('saveVariable')->assertHasNoErrors()
            ->assertSee('attempts')
            ->assertSet('canUndo', true);
        $this->assertSame(['attempts' => ['type' => 'number', 'value' => '3']], $this->declared($workflow));

        $editor->call('editVariable', 'attempts')
            ->assertSet('variableForm', ['name' => 'attempts', 'type' => 'number', 'value' => '3'])
            ->set('variableForm.name', 'renamed')->set('variableForm.type', 'string')->set('variableForm.value', 'none')
            ->call('saveVariable')->assertHasNoErrors();
        $this->assertSame(['attempts' => ['type' => 'string', 'value' => 'none']], $this->declared($workflow));

        $editor->call('undo');
        $this->assertSame(['attempts' => ['type' => 'number', 'value' => '3']], $this->declared($workflow));

        $editor->call('deleteVariable', 'attempts');
        $this->assertSame([], $this->declared($workflow));
        $this->assertArrayNotHasKey('variables', $workflow->refresh()->draft_graph);

        $editor->call('undo');
        $this->assertSame(['attempts'], array_keys($this->declared($workflow)));
    }

    public function test_the_form_refuses_a_bad_or_taken_name_and_a_starting_value_that_cannot_start_a_run(): void
    {
        $workflow = $this->draft(['taken' => ['string', '']]);
        $editor = $this->editor($workflow)->call('addVariable');

        $editor->set('variableForm.name', 'has-dash')->call('saveVariable')->assertHasErrors(['variableForm.name' => 'regex']);
        $editor->set('variableForm.name', 'taken')->call('saveVariable')->assertHasErrors(['variableForm.name' => 'not_in']);
        $editor->set('variableForm.name', 'fresh')->set('variableForm.type', 'number')->set('variableForm.value', 'lots')
            ->call('saveVariable')->assertHasErrors('variableForm.value')->assertSee('Expected Number, but got the text "lots".');
        $editor->set('variableForm.value', '{{ vars.taken }}')->call('saveVariable')->assertHasErrors('variableForm.value');

        $this->assertSame(['taken'], array_keys($this->declared($workflow)));
    }

    public function test_viewers_see_the_variables_but_cannot_change_them(): void
    {
        $workflow = $this->draft(['attempts' => ['number', '3']]);

        $this->editor($workflow, WorkspaceRole::Viewer)
            ->assertSee('attempts')
            ->assertDontSeeHtml('data-test="add-variable"')
            ->assertDontSeeHtml('data-test="edit-variable"');
        $this->editor($workflow, WorkspaceRole::Viewer)->call('addVariable')->assertForbidden();
        $this->editor($workflow, WorkspaceRole::Viewer)->set('variableForm.name', 'sneaky')->call('saveVariable')->assertForbidden();
        $this->editor($workflow, WorkspaceRole::Viewer)->call('deleteVariable', 'attempts')->assertForbidden();

        $this->assertSame(['attempts'], array_keys($this->declared($workflow)));
    }

    public function test_variables_are_published_discarded_and_rolled_back_with_the_draft(): void
    {
        $workflow = $this->draft(['attempts' => ['number', '3']]);
        $workflows = app(Workflows::class);
        $workflows->saveDraft($workflow, $workflow->draft());

        $first = $workflows->publish($workflow);
        $this->assertSame(['attempts'], array_keys($first->toGraph()->variables));
        $this->assertFalse($workflow->refresh()->hasUnpublishedChanges());

        $editor = $this->editor($workflow);
        $editor->call('addVariable')->set('variableForm.name', 'cursor')->call('saveVariable');
        $this->assertTrue($workflow->refresh()->hasUnpublishedChanges());

        $editor->call('discardDraft');
        $this->assertSame(['attempts'], array_keys($this->declared($workflow)));

        $editor->call('addVariable')->set('variableForm.name', 'cursor')->call('saveVariable');
        $workflows->publish($workflow->refresh());
        $workflows->rollback($workflow->refresh(), $first);

        $this->assertSame(['attempts'], array_keys($workflow->refresh()->publishedVersion->toGraph()->variables));
        $this->assertSame(['attempts'], array_keys($this->declared($workflow)));
    }

    public function test_the_update_node_lists_the_variables_and_offers_only_the_operations_that_fit_the_chosen_ones_type(): void
    {
        $workflow = $this->draft(['runs' => ['number', ''], 'log' => ['string', ''], 'enabled' => ['boolean', '']], 'core.variable_update', ['variable' => 'runs', 'operation' => 'increment', 'amount' => 2]);
        $node = app(NodeRegistry::class)->make('core.variable_update');
        $variables = WorkflowGraph::fromArray($workflow->draft_graph)->variables;
        $operation = collect($node->inputs())->firstWhere('name', 'operation');
        $offered = fn (?string $variable) => array_keys($operation->optionsFor(FieldFacts::for($node, ['variable' => $variable], [], $variables), $node->inputs()));

        $this->assertSame(['set', 'increment'], $offered('runs'));
        $this->assertSame(['set', 'append'], $offered('log'));
        $this->assertSame(['set'], $offered('enabled'));
        $this->assertSame(['set'], $offered('undeclared'));

        $this->editor($workflow)->call('selectNode', 'node')
            ->assertSee('runs · Number')->assertSee('log · String')
            ->assertSeeHtml('wire:key="field-node-amount"')
            ->set('form.variable', 'log')
            ->assertSet('form.operation', 'set')
            ->assertDontSeeHtml('wire:key="field-node-amount"')
            ->set('form.operation', 'append')
            ->set('form.value', 'hello');

        $this->assertSame(
            ['variable' => 'log', 'operation' => 'append', 'value' => 'hello'],
            WorkflowGraph::fromArray($workflow->refresh()->draft_graph)->node('node')->config,
        );
    }

    public function test_a_node_whose_variable_was_deleted_still_shows_which_one_it_used(): void
    {
        $workflow = $this->draft([], 'core.variable_update', ['variable' => 'gone', 'value' => 'x']);

        $this->editor($workflow)->call('selectNode', 'node')
            ->assertSee('gone (not declared)')
            ->assertSee('This workflow has no variable "gone".');
    }

    public function test_the_starting_value_field_has_an_insert_data_picker_that_follows_the_draft(): void
    {
        StoreEntry::factory()->for($this->workspace)->create(['key' => 'cursor']);
        $workflow = Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(
            ['start' => ['core.manual_trigger', ['fields' => [['name' => 'order_id', 'label' => 'Order', 'type' => 'number']]]], 'node' => 'core.compose'],
            ['start->node'],
        )]);

        $editor = $this->editor($workflow)
            ->assertSeeHtml('data-test="starting-value-field"')
            ->assertSeeHtml('data-path="trigger.values.order_id"')
            ->assertSeeHtml('data-path="store.cursor"')
            ->assertSeeHtml('data-path="execution.id"')
            ->assertDontSeeHtml('data-path="nodes.node.output.value"');

        $this->assertSame(['Trigger: start', 'Data store', 'Execution'], array_column($editor->instance()->startingValuePicker(), 'label'));

        $this->editor($workflow, WorkspaceRole::Viewer)->assertSeeHtml('data-test="starting-value-field"');
    }

    /**
     * @return array<string, string>|null
     */
    private function startingValuePreview(Testable $editor, string $type, string $template): ?array
    {
        return data_get($editor->set('variableForm.type', $type)->call('previewStartingValue', $template)->effects, 'returns.0');
    }

    public function test_a_starting_value_previews_as_the_value_a_run_would_start_with(): void
    {
        StoreEntry::factory()->for($this->workspace)->type(ValueType::Number)->holding(250)->create(['key' => 'threshold']);
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'node' => 'core.compose'], ['start->node']), $this->workspace);
        $editor = $this->editor($workflow);

        $this->assertSame(
            ['status' => 'value', 'value' => '250', 'type' => 'number', 'source' => __('workflows.editor.preview_no_runs_yet')],
            $this->startingValuePreview($editor, 'number', '{{ store.threshold }}'),
        );
        $this->assertSame('0', $this->startingValuePreview($editor, 'number', '{{ trigger.values.amount }}')['value']);
        $this->assertSame('empty', $this->startingValuePreview($editor, 'string', '{{ execution.started_at }}')['status']);
        $this->assertNull($this->startingValuePreview($editor, 'number', '12'));

        $run = $this->runWorkflow($workflow, ['values' => ['amount' => '12.5', 'tags' => '["a","b"]']]);
        $editor = $this->editor($workflow);

        $amount = $this->startingValuePreview($editor, 'number', '{{ trigger.values.amount }}');
        $this->assertSame(['value', '12.5', 'number'], [$amount['status'], $amount['value'], $amount['type']]);
        $this->assertStringContainsString(Execution::reference($run->id), $amount['source']);
        $this->assertSame('array', $this->startingValuePreview($editor, 'array', '{{ trigger.values.tags }}')['type']);
        $this->assertSame($run->started_at->toIso8601String(), $this->startingValuePreview($editor, 'datetime', '{{ execution.started_at }}')['value']);
    }

    public function test_a_starting_value_that_would_stop_a_run_from_starting_previews_the_reason(): void
    {
        StoreEntry::factory()->for($this->workspace)->holding('soon')->create(['key' => 'label']);
        $editor = $this->editor($this->draft([]));

        $this->assertSame(
            ['status' => 'error', 'value' => __('workflows.editor.starting_value_cannot_read', ['name' => 'nodes'])],
            array_slice($this->startingValuePreview($editor, 'string', '{{ nodes.node.output.value }}'), 0, 2),
        );
        $this->assertSame(
            ['status' => 'error', 'value' => __('engine.values.not_a_type', ['type' => 'Number', 'given' => 'the text "soon"'])],
            array_slice($this->startingValuePreview($editor, 'number', '{{ store.label }}'), 0, 2),
        );
        $this->assertSame(__('workflows.editor.starting_value_cannot_be_read'), $this->startingValuePreview($editor, 'string', '{{ upper( }}')['value']);
    }
}
