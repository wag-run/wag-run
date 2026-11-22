<?php

namespace Tests\Feature\Editor;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WorkflowEditorTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @param  array<string, mixed>|null  $graph
     * @return array{Workspace, Workflow, User}
     */
    private function setUpEditor(?array $graph = null, WorkspaceRole $role = WorkspaceRole::Editor): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->memberOf($workspace, $role)->create();
        $workflow = Workflow::factory()->for($workspace)->create(['draft_graph' => $graph ?? $this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'https://api.example.com']], 'check' => ['core.conditional', ['condition' => 'true']]],
            ['start->fetch', 'fetch->check'],
        )]);

        return [$workspace, $workflow, $user];
    }

    private function editor(Workspace $workspace, Workflow $workflow, User $user): Testable
    {
        return Livewire::actingAs($user)->test('pages::workflows.edit', ['workspace' => $workspace, 'workflow' => $workflow]);
    }

    public function test_viewers_see_the_editor_read_only_and_guests_are_refused(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $guest = User::factory()->memberOf($workspace, WorkspaceRole::Guest)->create();

        $this->actingAs($viewer)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))
            ->assertOk()
            ->assertDontSee('data-test="palette-core.http_request"', false)
            ->assertDontSee('data-test="publish-button"', false);

        $this->actingAs($guest)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))->assertForbidden();
    }

    public function test_a_workflow_from_another_workspace_is_not_found(): void
    {
        [$workspace, , $user] = $this->setUpEditor();
        $foreign = Workflow::factory()->create();

        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$workspace, $foreign]))->assertNotFound();
    }

    public function test_viewers_cannot_change_the_draft(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->editor($workspace, $workflow, $viewer)->call('addNode', 'core.compose')->assertForbidden();
        $this->editor($workspace, $workflow, $viewer)->call('canConnect', 'check', 'start')->assertReturned(['allowed' => false, 'reason' => 'You can view this workflow but not edit it.']);
        $this->editor($workspace, $workflow, $viewer)->call('nodesChanged', ['type' => 'remove', 'nodes' => [['id' => 'fetch']]]);

        $this->assertCount(3, $workflow->refresh()->draft()->nodes);
    }

    public function test_adding_a_node_saves_it_with_defaults_after_the_selected_node(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'check')
            ->call('addNode', 'core.compose')
            ->assertSet('selected', 'compose')
            ->call('selectNode', 'check')
            ->call('addNode', 'core.compose');

        $graph = $workflow->refresh()->draft();
        $this->assertSame('template', $graph->node('compose')->config['mode']);
        $this->assertTrue($graph->hasEdge('check', 'true', 'compose'));
        $this->assertTrue($graph->hasEdge('check', 'false', 'compose_2'), 'the second node takes the next free output');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function refusedConnections(): array
    {
        return [
            'cycle' => ['check', 'true', 'fetch', 'That would create a cycle. Use a For loop node to repeat steps.'],
            'duplicate' => ['start', 'main', 'fetch', 'These nodes are already connected.'],
            'into a trigger' => ['fetch', 'error', 'start', 'Triggers start a workflow, so nothing can connect into them.'],
            'unknown port' => ['fetch', 'sideways', 'check', 'HTTP call has no output named "sideways".'],
            'to itself' => ['fetch', 'main', 'fetch', 'A node cannot connect to itself.'],
        ];
    }

    #[DataProvider('refusedConnections')]
    public function test_refuses_connections_with_a_reason(string $source, string $port, string $target, string $reason): void
    {
        $graph = $this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'x']], 'check' => ['core.conditional', ['condition' => 'true']]],
            ['start->fetch', 'fetch->check'],
        );
        $graph['nodes'][1]['name'] = 'HTTP call';
        [$workspace, $workflow, $user] = $this->setUpEditor($graph);

        $this->editor($workspace, $workflow, $user)
            ->call('canConnect', $source, $target, $port, 'in')
            ->assertReturned(['allowed' => false, 'reason' => $reason]);
    }

    public function test_canvas_events_update_the_saved_draft(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('canConnect', 'fetch', 'check', 'error', 'in')->assertReturned(true);
        $editor->call('onConnect', 'fetch', 'check', 'error', 'in');
        $this->assertTrue($workflow->refresh()->draft()->hasEdge('fetch', 'error', 'check'));

        $editor->call('moveNode', 'fetch', ['x' => 412.6, 'y' => 98.2]);
        $this->assertSame(['x' => 413.0, 'y' => 98.0], $workflow->refresh()->draft()->node('fetch')->position);

        $editor->call('edgesChanged', ['type' => 'remove', 'edges' => [['id' => 'client-id', 'source' => 'fetch', 'sourceHandle' => 'error', 'target' => 'check']]]);
        $this->assertFalse($workflow->refresh()->draft()->hasEdge('fetch', 'error', 'check'));

        $editor->call('nodesChanged', ['type' => 'remove', 'nodes' => [['id' => 'fetch']]]);
        $graph = $workflow->refresh()->draft();
        $this->assertFalse($graph->has('fetch'));
        $this->assertSame([], $graph->edges);
        $this->assertNotNull($workflow->draft_updated_at);
    }

    public function test_inspector_edits_save_the_node_config(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'fetch')
            ->assertSet('form.url', 'https://api.example.com')
            ->set('nodeName', 'Fetch order')
            ->set('form.method', 'POST')
            ->set('form.headers', [['key' => 'X-Order', 'value' => '{{ trigger.id }}'], ['key' => '', 'value' => 'ignored']])
            ->set('retry.max_retries', 3)
            ->assertHasNoErrors();

        $node = $workflow->refresh()->draft()->node('fetch');
        $this->assertSame('Fetch order', $node->name);
        $this->assertSame('POST', $node->config['method']);
        $this->assertSame(['X-Order' => '{{ trigger.id }}'], $node->config['headers']);
        $this->assertSame(['max_retries' => 3, 'backoff_seconds' => 30], $node->retry);
    }

    public function test_reducing_outputs_drops_connections_from_removed_outputs(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'split' => ['core.branch', ['branches' => 3]], 'a' => 'core.compose', 'b' => 'core.compose', 'c' => 'core.compose'],
            ['start->split', 'split:branch_1->a', 'split:branch_2->b', 'split:branch_3->c'],
        ));

        $this->editor($workspace, $workflow, $user)->call('selectNode', 'split')->set('form.branches', '2');

        $graph = $workflow->refresh()->draft();
        $this->assertTrue($graph->hasEdge('split', 'branch_2', 'b'));
        $this->assertFalse($graph->hasEdge('split', 'branch_3', 'c'));
    }

    public function test_publish_reports_problems_or_creates_a_version(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(['start' => 'core.manual_trigger', 'fetch' => 'core.http_request'], ['start->fetch']));
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('publish')->assertSet('publishErrors', ['Node "fetch" needs a value for "URL".']);
        $this->assertNull($workflow->refresh()->published_version_id);

        $editor->call('selectNode', 'fetch')->set('form.url', 'https://api.example.com')->call('publish')->assertSet('publishErrors', []);

        $this->assertSame(1, $workflow->refresh()->publishedVersion->number);
        $this->assertFalse($workflow->hasUnpublishedChanges());
    }

    public function test_rollback_republishes_an_old_version_and_resets_the_draft(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $v1 = app(Workflows::class)->publish($workflow);
        app(Workflows::class)->saveDraft($workflow, $workflow->draft()->withoutNode('check'));
        app(Workflows::class)->publish($workflow->refresh());

        $this->editor($workspace, $workflow, $user)->call('rollback', $v1->id);

        $workflow->refresh();
        $this->assertSame(3, $workflow->publishedVersion->number);
        $this->assertTrue($workflow->draft()->has('check'));
    }

    public function test_viewers_cannot_publish_and_editors_can_save_settings(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->editor($workspace, $workflow, $viewer)->call('publish')->assertForbidden();

        $this->editor($workspace, $workflow, $user)
            ->set('name', 'Renamed')
            ->set('concurrency', 'skip')
            ->set('customRetention', true)
            ->set('retentionDays', 7)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $workflow->refresh();
        $this->assertSame('Renamed', $workflow->name);
        $this->assertSame('skip', $workflow->concurrency->value);
        $this->assertSame(7, $workflow->retention_days);
    }
}
