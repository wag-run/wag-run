<?php

namespace Tests\Feature\Editor;

use App\Engine\Redactor;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\Secret;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ExpressionPreviewTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    /**
     * @return array<string, mixed>
     */
    private function twoStepGraph(): array
    {
        return $this->graph(
            ['start' => 'start', 'first' => ['emit', ['value' => 'from first']], 'second' => ['emit', ['value' => 'x']]],
            ['start->first', 'first->second'],
        );
    }

    private function editorFor(Workflow $workflow, string $nodeId): Testable
    {
        $user = User::factory()->memberOf($workflow->workspace, WorkspaceRole::Editor)->create();

        return Livewire::actingAs($user)
            ->test('pages::workflows.edit', ['workspace' => $workflow->workspace, 'workflow' => $workflow])
            ->call('selectNode', $nodeId);
    }

    /**
     * @return array<string, string>|null
     */
    private function preview(Testable $editor, string $template): ?array
    {
        return data_get($editor->call('previewTemplate', $template)->effects, 'returns.0');
    }

    public function test_without_runs_functions_are_worked_out_and_references_read_as_empty(): void
    {
        $editor = $this->editorFor($this->publishedWorkflow($this->twoStepGraph()), 'second');

        $preview = $this->preview($editor, '{{ upper("hi") }}-{{ trigger.name }}-{{ nodes.first.output.value }}');

        $this->assertSame(['status' => 'value', 'value' => 'HI--', 'type' => 'text', 'source' => __('workflows.editor.preview_no_runs_yet')], $preview);
    }

    public function test_uses_the_data_the_node_saw_in_its_latest_run(): void
    {
        $workflow = $this->publishedWorkflow($this->twoStepGraph());
        $this->runWorkflow($workflow, ['name' => 'Old']);
        $execution = $this->runWorkflow($workflow, ['name' => 'Ada']);
        $editor = $this->editorFor($workflow, 'second');

        $text = $this->preview($editor, 'Hi {{ trigger.name }}, {{ nodes.first.output.value }}');
        $list = $this->preview($editor, '{{ nodes.first.output.saw }}');

        $this->assertSame('Hi Ada, from first', $text['value']);
        $this->assertStringContainsString(Execution::reference($execution->id), $text['source']);
        $this->assertSame(['status' => 'value', 'value' => "[\n    \"start\"\n]", 'type' => 'array'], array_slice($list, 0, 3));
    }

    public function test_a_node_that_has_not_run_yet_uses_what_the_latest_run_produced(): void
    {
        $workflow = $this->publishedWorkflow($this->twoStepGraph());
        $this->runWorkflow($workflow, ['name' => 'Ada']);
        $workflow->update(['draft_graph' => $this->graph(
            ['start' => 'start', 'first' => ['emit', ['value' => 'from first']], 'second' => ['emit', ['value' => 'x']], 'added' => ['emit', ['value' => 'y']]],
            ['start->first', 'first->second', 'second->added'],
        )]);
        $editor = $this->editorFor($workflow, 'added');

        $preview = $this->preview($editor, '{{ trigger.name }} {{ nodes.second.output.value }} {{ execution.trigger.type }}');

        $this->assertSame('Ada x test.start', $preview['value']);
    }

    public function test_secrets_are_masked(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'api_key', 'value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->twoStepGraph(), $workspace);
        $this->runWorkflow($workflow);
        $editor = $this->editorFor($workflow, 'second');

        $preview = $this->preview($editor, 'key={{ secrets.api_key }}');

        $this->assertSame('key='.Redactor::MASK, $preview['value']);
    }

    public function test_an_expression_that_cannot_be_worked_out_previews_the_error(): void
    {
        $editor = $this->editorFor($this->publishedWorkflow($this->twoStepGraph()), 'second');

        $preview = $this->preview($editor, '{{ upper( }}');

        $this->assertSame('error', $preview['status']);
        $this->assertStringStartsWith('Expression error:', $preview['value']);
    }

    public function test_an_empty_result_is_marked_empty(): void
    {
        $editor = $this->editorFor($this->publishedWorkflow($this->twoStepGraph()), 'second');

        $preview = $this->preview($editor, '{{ trigger.missing }}');

        $this->assertSame(['status' => 'empty', 'value' => '', 'type' => 'empty'], array_slice($preview, 0, 3));
    }

    public function test_text_without_placeholders_is_not_previewed(): void
    {
        $editor = $this->editorFor($this->publishedWorkflow($this->twoStepGraph()), 'second');

        $preview = $this->preview($editor, 'plain text');

        $this->assertNull($preview);
    }

    public function test_long_results_are_cut(): void
    {
        $editor = $this->editorFor($this->publishedWorkflow($this->twoStepGraph()), 'second');

        $preview = $this->preview($editor, '{{ join(split(format("%03000d", 0), ""), "") }}');

        $this->assertSame(str_repeat('0', 2000).'…', $preview['value']);
    }
}
