<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\RunCanvas;
use App\Engine\Graph\GraphValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\Workflows;
use Carbon\CarbonImmutable;
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
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'https://api.example.com']], 'check' => ['core.conditional', ['condition' => '{{ true }}']]],
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

    public function test_viewers_can_move_around_the_canvas_without_unlocking_it(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $html = $this->editor($workspace, $workflow, $viewer)->html();

        $this->assertStringContainsString('flowCanvas(', $html);
        $this->assertDoesNotMatchRegularExpression('/interactive(\\\\u0022|&quot;|"):false/', $html);
    }

    public function test_the_canvas_can_mark_nodes_no_trigger_leads_to(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))
            ->assertOk()
            ->assertSee('wagHasNoInput(node.id, nodes, edges)', false)
            ->assertSee('data-test="no-input"', false)
            ->assertSee('x-data="wagEdgeClasses"', false)
            ->assertSee('No input');
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
        $this->assertSame(['parse_json' => false], $graph->node('compose')->config);
        $this->assertTrue($graph->hasEdge('check', 'true', 'compose'));
        $this->assertTrue($graph->hasEdge('check', 'false', 'compose_2'), 'the second node takes the next free output');
    }

    public function test_a_node_dropped_on_the_canvas_lands_where_it_was_dropped_without_connecting(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'check')
            ->call('addNode', 'core.compose', ['x' => 610.4, 'y' => 330])
            ->assertSet('selected', 'compose');

        $graph = $workflow->refresh()->draft();
        $this->assertSame(['x' => 500.0, 'y' => 300.0], $graph->node('compose')->position);
        $this->assertSame([], $graph->incoming('compose'));
    }

    public function test_undo_and_redo_step_through_draft_changes_and_a_new_change_clears_redo(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $editor = $this->editor($workspace, $workflow, $user)->assertSet('canUndo', false);

        $editor->call('addNode', 'core.compose')->call('moveNode', 'compose', ['x' => 900, 'y' => 40]);

        $editor->call('undo')->assertSet('canRedo', true);
        $this->assertSame(['x' => 300.0, 'y' => 160.0], $workflow->refresh()->draft()->node('compose')->position);

        $editor->call('undo')->assertSet('canUndo', false)->assertSet('selected', null);
        $this->assertFalse($workflow->refresh()->draft()->has('compose'));

        $editor->call('redo');
        $this->assertTrue($workflow->refresh()->draft()->has('compose'));

        $editor->call('moveNode', 'fetch', ['x' => 5, 'y' => 5])->assertSet('canRedo', false);
    }

    public function test_copied_nodes_paste_with_new_ids_their_connections_and_an_offset(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $editor = $this->editor($workspace, $workflow, $user);
        $original = $workflow->draft();

        $editor->call('copyNodes', ['fetch', 'check', 'missing'])->assertSet('hasClipboard', true);
        $editor->call('pasteNodes');

        $graph = $workflow->refresh()->draft();
        $this->assertSame($graph->node('fetch')->config, $graph->node('fetch_2')->config);
        $this->assertSame(['x' => $original->node('check')->position['x'] + 40, 'y' => $original->node('check')->position['y'] + 40], $graph->node('check_2')->position);
        $this->assertTrue($graph->hasEdge('fetch_2', 'main', 'check_2'));
        $this->assertSame([], $graph->incoming('fetch_2'), 'connections to nodes outside the copy are left out');

        $editor->call('pasteNodes', ['x' => 1000, 'y' => 500]);

        $graph = $workflow->refresh()->draft();
        $this->assertSame(['x' => 1000.0, 'y' => 500.0], $graph->node('fetch_3')->position, 'the copy starts at the position given');
    }

    public function test_cut_removes_the_nodes_and_keeps_them_for_pasting(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'check');

        $editor->call('cutNodes', ['check'])->assertSet('selected', null);
        $this->assertFalse($workflow->refresh()->draft()->has('check'));

        $editor->call('pasteNodes');
        $this->assertTrue($workflow->refresh()->draft()->has('check'));
    }

    public function test_viewers_can_copy_but_cannot_paste_or_undo(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();
        $editor = $this->editor($workspace, $workflow, $viewer);

        $editor->call('copyNodes', ['fetch'])->assertSet('hasClipboard', true);
        $editor->call('pasteNodes')->assertForbidden();
        $this->editor($workspace, $workflow, $viewer)->call('undo')->assertForbidden();
        $this->assertFalse($workflow->refresh()->draft()->has('fetch_2'));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function refusedConnections(): array
    {
        return [
            'cycle' => ['check', 'true', 'fetch', 'That would create a cycle. Use a For loop node to repeat steps.'],
            'duplicate' => ['start', 'main', 'fetch', 'These nodes are already connected.'],
            'into a trigger' => ['fetch', 'main', 'start', 'Triggers start a workflow, so nothing can connect into them.'],
            'error path off' => ['fetch', 'error', 'check', 'Turn on the "on error" path for HTTP call in its settings to connect its errors.'],
            'unknown port' => ['fetch', 'sideways', 'check', 'HTTP call has no output named "sideways".'],
            'to itself' => ['fetch', 'main', 'fetch', 'A node cannot connect to itself.'],
        ];
    }

    #[DataProvider('refusedConnections')]
    public function test_refuses_connections_with_a_reason(string $source, string $port, string $target, string $reason): void
    {
        $graph = $this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'x']], 'check' => ['core.conditional', ['condition' => '{{ true }}']]],
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
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'fetch')->set('errorPath', true);

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
            ->set('form.headers', [['key' => 'X-Order', 'value' => '{{ trigger.values.id }}'], ['key' => '', 'value' => 'ignored']])
            ->set('retry.max_retries', 3)
            ->assertHasNoErrors();

        $node = $workflow->refresh()->draft()->node('fetch');
        $this->assertSame('Fetch order', $node->name);
        $this->assertSame('POST', $node->config['method']);
        $this->assertSame(['X-Order' => '{{ trigger.values.id }}'], $node->config['headers']);
        $this->assertSame(['max_retries' => 3, 'backoff_seconds' => 30], $node->retry);
    }

    public function test_the_error_path_is_shown_only_when_turned_on_and_turning_it_off_drops_its_connections(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'x']], 'check' => ['core.conditional', ['condition' => '{{ true }}']], 'compose' => 'core.compose'],
            ['start->fetch', 'fetch->check', 'fetch:error->compose'],
        ));
        $editor = $this->editor($workspace, $workflow, $user);

        $this->assertTrue(collect($editor->get('canvas')['nodes'])->firstWhere('id', 'fetch')['data']['hasErrorPort'], 'a node with error connections keeps its error output');
        $this->assertFalse(collect($editor->get('canvas')['nodes'])->firstWhere('id', 'check')['data']['hasErrorPort']);

        $editor->call('selectNode', 'fetch')->assertSet('errorPath', true)->set('errorPath', false);

        $graph = $workflow->refresh()->draft();
        $this->assertFalse($graph->node('fetch')->errorPath);
        $this->assertFalse($graph->hasEdge('fetch', 'error', 'compose'));
        $this->assertTrue($graph->hasEdge('fetch', 'main', 'check'));

        $editor->set('errorPath', true);
        $this->assertTrue($workflow->refresh()->draft()->node('fetch')->errorPath);
    }

    public function test_an_action_can_be_set_to_wait_for_all_its_incoming_branches_and_keeps_the_setting_when_moved(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'x']], 'merge' => 'core.merge'],
            ['start->fetch', 'fetch->merge'],
        ));
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('selectNode', 'fetch')->assertSet('waitForAll', false)->assertSee('data-test="wait-for-all-checkbox"', false)->set('waitForAll', true);
        $editor->call('moveNode', 'fetch', ['x' => 40, 'y' => 60]);
        $this->assertTrue($workflow->refresh()->draft()->node('fetch')->waitForAll);
        $this->assertTrue($workflow->draft()->toArray()['nodes'][1]['wait_for_all']);

        $editor->call('selectNode', 'merge')->assertDontSee('data-test="wait-for-all-checkbox"', false)->set('waitForAll', true);
        $editor->call('selectNode', 'start')->assertDontSee('data-test="wait-for-all-checkbox"', false);
        $this->assertFalse($workflow->refresh()->draft()->node('merge')->waitForAll);
    }

    public function test_removing_a_switch_case_drops_its_connections_and_moves_later_ones_up(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'route' => ['core.switch', ['value' => 'x', 'cases' => [['value' => 'free'], ['value' => 'pro'], ['value' => 'team']]]],
                'a' => 'core.compose', 'b' => 'core.compose', 'c' => 'core.compose', 'other' => 'core.compose'],
            ['start->route', 'route:case_1->a', 'route:case_2->b', 'route:case_3->c', 'route:default->other'],
        ));
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('selectNode', 'route')->call('removeRow', 'cases', 0);

        $graph = $workflow->refresh()->draft();
        $this->assertSame([['value' => 'pro'], ['value' => 'team']], $graph->node('route')->config['cases']);
        $connections = array_map(fn ($edge) => "{$edge->sourcePort}->{$edge->target}", $graph->outgoing('route'));
        sort($connections);
        $this->assertSame(['case_1->b', 'case_2->c', 'default->other'], $connections);
        $this->assertSame(['= pro', '= team', 'default'], array_column(collect($editor->get('canvas')['nodes'])->firstWhere('id', 'route')['data']['ports'], 'label'));
    }

    public function test_trigger_other_workflow_does_not_offer_the_workflow_itself(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $other = Workflow::factory()->for($workspace)->create(['name' => 'Billing']);

        $this->editor($workspace, $workflow, $user)->assertSet('choices.workflows', [$other->id => 'Billing']);
    }

    public function test_compose_and_conditions_offer_the_data_picker_which_inserts_placeholders(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'build' => ['core.compose', ['template' => 'Hi']], 'check' => ['core.conditional', ['condition' => '{{ true }}']]],
            ['start->build', 'build->check'],
        ));
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('selectNode', 'build')->assertSeeHtml('data-test="data-picker"')->assertSeeHtml('data-token="{{ execution.id }}"');
        $editor->call('selectNode', 'check')->assertSeeHtml('data-test="data-picker"')->assertSeeHtml('data-token="{{ nodes.build.output.value }}"');
        $editor->assertSeeHtml('data-test="function-picker"')->assertSeeHtml('data-function="upper"')->assertSee('upper(text)');
    }

    public function test_rows_of_a_templated_list_offer_the_data_picker_and_preview_but_other_lists_do_not(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'append' => ['google.append_sheet_row', ['values' => [['value' => '{{ trigger.values.name }}']]]], 'route' => ['core.switch', ['value' => 'x', 'cases' => [['value' => 'pro']]]]],
            ['start->append', 'append->route'],
        ));
        $editor = $this->editor($workspace, $workflow, $user);

        $editor->call('selectNode', 'append')
            ->assertSeeHtmlInOrder(['class="wag-expr relative" data-test="list-sub-field"', 'name="form.values.0.value"', 'class="wag-expr-layer"', 'data-test="data-picker"', 'data-test="expression-preview"']);
        $editor->call('selectNode', 'route')
            ->assertSeeHtml('data-test="list-sub-field"')
            ->assertDontSeeHtml('class="wag-expr relative" data-test="list-sub-field"');
    }

    public function test_a_json_body_gets_the_json_editor_and_a_raw_body_a_plain_textarea(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'https://api.example.com', 'body_type' => 'json', 'body' => '{"id": {{ trigger.values.id }}}']]],
            ['start->fetch'],
        ));
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'fetch');

        $editor->assertSeeHtml('data-test="json-editor"')->assertSeeHtml('data-test="json-format"')->assertSeeHtml('data-test="data-picker"')
            ->assertSeeHtml('style="display: none" x-show="state === \'valid\'"')
            ->assertSeeHtml('style="display: none" x-show="state === \'invalid\'"');

        $editor->set('form.body_type', 'raw')->assertDontSeeHtml('data-test="json-editor"');
        $this->assertSame('{"id": {{ trigger.values.id }}}', $workflow->refresh()->draft()->node('fetch')->config['body']);
    }

    public function test_a_cron_schedule_is_put_into_words_with_its_next_runs_and_offers_presets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27 15:00', 'UTC'));
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['tick' => ['core.schedule_trigger', ['mode' => 'cron', 'cron' => '0 9 * * 1-5', 'timezone' => 'America/New_York']]],
        ));
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'tick');

        $editor->assertSeeHtml('data-test="cron-presets"')
            ->assertSeeHtml('data-test="timezone-field"')
            ->assertSeeHtml('<optgroup label="Europe">')
            ->assertSeeHtml('value="America/New_York"')
            ->assertSee('At 09:00 on weekdays')
            ->assertSee('Next runs (America/New_York)')
            ->assertSee('Mon 30 Nov 2026, 09:00');

        $editor->set('form.cron', 'every day')->assertSee('Not a valid cron expression.')->assertDontSee('Next runs');
        $editor->set('form.cron', '0 9 * * *')->set('form.timezone', 'Mars/Olympus')->assertSee('Every day at 09:00')->assertSee('Mars/Olympus is not a time zone')
            ->assertSee('Mars/Olympus (not a known time zone)');
    }

    public function test_publishing_refuses_an_invalid_cron_expression_or_time_zone(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['tick' => ['core.schedule_trigger', ['mode' => 'cron', 'cron' => 'every day', 'timezone' => 'Mars/Olympus']]],
        ));

        $this->editor($workspace, $workflow, $user)->call('publish')->assertSet('publishErrors', [
            'Node "tick": Not a valid cron expression. Use five parts: minute, hour, day of the month, month, and day of the week.',
            'Node "tick": Mars/Olympus is not a time zone this server knows, such as Europe/London or America/New_York.',
        ]);
        $this->assertNull($workflow->refresh()->published_version_id);
    }

    public function test_the_run_button_explains_itself_only_when_the_draft_differs_from_the_published_version(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(['start' => 'core.manual_trigger'], []));
        $owner = User::factory()->memberOf($workspace, WorkspaceRole::Admin)->create();
        app(Workflows::class)->saveDraft($workflow, $workflow->draft());
        app(Workflows::class)->publish($workflow, $owner);

        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))
            ->assertSee('data-test="run-button"', false)
            ->assertDontSee('Runs the published version, not the draft.');

        $this->editor($workspace, $workflow, $user)->call('moveNode', 'start', ['x' => 10, 'y' => 10]);

        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))
            ->assertSee('Runs the published version, not the draft.');
    }

    public function test_discarding_the_draft_returns_to_the_published_version_and_can_be_undone(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        app(Workflows::class)->saveDraft($workflow, $workflow->draft());
        app(Workflows::class)->publish($workflow, $user);
        $editor = $this->editor($workspace, $workflow, $user)->call('addNode', 'core.compose');
        $this->assertTrue($workflow->refresh()->hasUnpublishedChanges());

        $editor->assertSeeHtml('data-test="discard-draft"')->assertSeeHtml('ok && $wire.discardDraft()')->call('discardDraft');

        $this->assertFalse($workflow->refresh()->hasUnpublishedChanges());
        $this->assertFalse($workflow->draft()->has('compose'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'workflow.draft_discarded']);

        $editor->call('undo');
        $this->assertTrue($workflow->refresh()->draft()->has('compose'));
    }

    public function test_viewers_cannot_discard_the_draft(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->editor($workspace, $workflow, $viewer)
            ->assertDontSeeHtml('data-test="discard-draft"')
            ->call('discardDraft')
            ->assertForbidden();
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
            ->set('customRunLimit', true)
            ->set('maxRunsPerMinute', '0')
            ->call('saveSettings')
            ->assertHasErrors(['maxRunsPerMinute' => 'min'])
            ->set('maxRunsPerMinute', '200')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $workflow->refresh();
        $this->assertSame('Renamed', $workflow->name);
        $this->assertSame('skip', $workflow->concurrency->value);
        $this->assertSame(7, $workflow->retention_days);
        $this->assertSame(200, $workflow->max_runs_per_minute);
    }

    public function test_the_palette_leaves_out_sticky_notes_which_come_from_the_context_menu(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$workspace, $workflow]))
            ->assertOk()
            ->assertSee('data-test="palette-core.http_request"', false)
            ->assertDontSee('data-test="palette-core.note"', false)
            ->assertSee(__('workflows.edit.add_sticky_note'));
    }

    public function test_the_palette_nests_other_apps_triggers_under_triggers(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $palette = $this->editor($workspace, $workflow, $user)->instance()->palette();
        $types = fn (string $folder) => array_column($palette[$folder]['items'], 'type');

        $this->assertSame('Triggers', $palette['']['groups'][0]['name']);
        $this->assertSame(['key' => 'Triggers › Other apps', 'name' => 'Other apps', 'icon' => 'tag', 'count' => 4], $palette['Triggers']['groups'][0]);
        $this->assertSame(['core.manual_trigger', 'core.webhook_trigger', 'core.schedule_trigger', 'core.form_trigger'], $types('Triggers'));
        $this->assertSame([], $types('Triggers › Other apps'));
        $this->assertSame(['github.new_tag', 'github.new_release', 'github.workflow_run_failed'], $types('Triggers › Other apps › GitHub'));
        $this->assertSame(['investbrain.new_transaction'], $types('Triggers › Other apps › Investbrain'));
        $this->assertContains('investbrain.add_transaction', $types('Investbrain'));
        $this->assertContains('github.create_issue', $types('GitHub'));
    }

    public function test_a_sticky_note_goes_where_the_canvas_was_clicked_and_its_text_and_color_save(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();

        $editor = $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'check')
            ->call('addNode', 'core.note', ['x' => 500, 'y' => 400])
            ->assertSet('selected', 'note')
            ->set('form.text', "Ask finance before\nchanging the limit")
            ->set('form.color', 'blue')
            ->set('form.opacity', '50');

        $graph = $workflow->refresh()->draft_graph;
        $note = collect($graph['nodes'])->firstWhere('id', 'note');
        $this->assertSame(['text' => "Ask finance before\nchanging the limit", 'color' => 'blue', 'opacity' => '50'], $note['config']);
        $this->assertEquals(['x' => 390, 'y' => 370], $note['position']);
        $this->assertSame([], collect($graph['edges'])->filter(fn (array $edge) => $edge['target'] === 'note')->all());

        $canvasNote = collect($editor->instance()->canvas['nodes'])->firstWhere('id', 'note');
        $this->assertTrue($canvasNote['data']['isNote']);
        $this->assertSame("Ask finance before\nchanging the limit", $canvasNote['data']['text']);
        $this->assertSame(50, $canvasNote['data']['opacity']);
        $this->assertFalse($canvasNote['connectable']);
    }

    public function test_sticky_notes_cannot_be_connected_and_never_run(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => ['core.http_request', ['url' => 'https://api.example.com']], 'note' => ['core.note', ['text' => 'Hi']]],
            ['start->fetch'],
        ));

        $this->editor($workspace, $workflow, $user)
            ->call('canConnect', 'fetch', 'note', 'main', 'in')
            ->assertReturned(['allowed' => false, 'reason' => 'Sticky notes cannot be connected.']);

        $this->assertContains('Sticky notes cannot be connected.', app(GraphValidator::class)->validate(WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'note' => ['core.note', ['text' => 'Hi']]],
            ['start->note'],
        ))));

        $published = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'done' => ['core.compose', ['template' => 'ok']], 'note' => ['core.note', ['text' => 'Hi']]],
            ['start->done'],
        ), $workspace);
        $execution = $this->runWorkflow($published);

        $this->assertSame(['start', 'done'], $this->nodesRun($execution));
        $runNote = collect(app(RunCanvas::class)->toCanvas($execution->refresh(), $execution->steps)['nodes'])->firstWhere('id', 'note');
        $this->assertArrayNotHasKey('status', $runNote['data']);
    }

    public function test_sticky_note_text_is_shown_as_markdown_without_scripts_or_unsafe_links(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'note' => ['core.note', ['text' => "# Refunds\n**Ask** finance\nfirst\n\n- [x] checked\n\n[Policy](https://wiki.example.com/refunds) <script>alert(1)</script> [trap](javascript:alert(1))"]]],
        ));

        $html = collect($this->editor($workspace, $workflow, $user)->instance()->canvas['nodes'])->firstWhere('id', 'note')['data']['html'];

        $this->assertStringContainsString('<h1>Refunds</h1>', $html);
        $this->assertStringContainsString("<strong>Ask</strong> finance<br>\nfirst", $html);
        $this->assertStringContainsString('<input checked="" disabled="" type="checkbox"> checked', $html);
        $this->assertStringContainsString('<a rel="noopener noreferrer" target="_blank" href="https://wiki.example.com/refunds">Policy</a>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_a_sticky_note_takes_a_preset_or_a_hex_color_and_anything_else_falls_back_to_yellow(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            ['start' => 'core.manual_trigger', 'note' => ['core.note', ['text' => 'Hi']]],
        ));
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'note');
        $colorOf = fn () => collect($editor->instance()->canvas['nodes'])->firstWhere('id', 'note')['data'];

        $editor->set('form.color', 'blue');
        unset($editor->instance()->canvas);
        $this->assertSame(['blue', null], [$colorOf()['color'], $colorOf()['custom']]);

        $editor->set('form.color', '#1E3A8A');
        unset($editor->instance()->canvas);
        $this->assertSame(['fill' => '#1e3a8a', 'line' => '#9aa6ca', 'ink' => '#fafaf9'], $colorOf()['custom']);

        $editor->set('form.color', '#FFF');
        unset($editor->instance()->canvas);
        $this->assertSame(['fill' => '#ffffff', 'line' => '#ffffff', 'ink' => '#1c1917'], $colorOf()['custom']);

        $editor->set('form.color', 'red');
        unset($editor->instance()->canvas);
        $this->assertSame(['yellow', null], [$colorOf()['color'], $colorOf()['custom']]);
    }

    public function test_retries_are_off_until_turned_on_and_turning_them_off_clears_them(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $retryOf = fn () => collect($workflow->refresh()->draft_graph['nodes'])->firstWhere('id', 'fetch')['retry'] ?? null;

        $editor = $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'fetch')
            ->assertSet('retryEnabled', false)
            ->assertDontSeeHtml('data-test="retry-settings"')
            ->set('retryEnabled', true)
            ->assertSeeHtml('data-test="retry-settings"');
        $this->assertSame(['max_retries' => 3, 'backoff_seconds' => 30], $retryOf());

        $editor->set('retry.max_retries', 5)->call('deselect')->call('selectNode', 'fetch')->assertSet('retryEnabled', true);
        $this->assertSame(5, $retryOf()['max_retries']);

        $editor->set('retryEnabled', false)->assertDontSeeHtml('data-test="retry-settings"');
        $this->assertNull($retryOf());
    }

    public function test_changing_a_node_id_moves_its_connections_and_rewrites_references_to_it(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor($this->graph(
            [
                'start' => 'core.manual_trigger',
                'fetch' => ['core.http_request', ['url' => 'https://api.example.com']],
                'fetch_2' => ['core.http_request', ['url' => '{{ nodes.fetch_2.output.url }}', 'headers' => ['X-Id' => "{{ nodes['fetch'].output.id }}", 'X-Raw' => '{{ nodes["fetch"]?.output }}']]],
                'check' => ['core.conditional', ['condition' => '{{ nodes.fetch.output.status == 200 and nodes?.fetch.output.ok }}']],
                'filter' => ['core.filter', ['items' => '{{ nodes.fetch.output.body.items }}', 'condition' => '{{ item.id == nodes.fetch.output.id and item.nodes.fetch }}']],
            ],
            ['start->fetch', 'fetch->check', 'check:true->filter', 'fetch->fetch_2'],
        ));

        $editor = $this->editor($workspace, $workflow, $user)
            ->call('selectNode', 'fetch')
            ->call('startEditingNodeId')
            ->assertSeeHtml('data-test="node-id-form"')
            ->set('newNodeId', ' lookup ')
            ->call('saveNodeId')
            ->assertHasNoErrors()
            ->assertSet('selected', 'lookup')
            ->assertSet('editingNodeId', false)
            ->assertSee('nodes.lookup.output');

        $graph = $workflow->refresh()->draft();
        $this->assertFalse($graph->has('fetch'));
        $this->assertSame('https://api.example.com', $graph->node('lookup')->config['url']);
        $this->assertTrue($graph->hasEdge('start', 'main', 'lookup'));
        $this->assertTrue($graph->hasEdge('lookup', 'main', 'check'));
        $this->assertTrue($graph->hasEdge('lookup', 'main', 'fetch_2'));
        $this->assertSame('{{ nodes.lookup.output.status == 200 and nodes?.lookup.output.ok }}', $graph->node('check')->config['condition']);
        $this->assertSame('{{ item.id == nodes.lookup.output.id and item.nodes.fetch }}', $graph->node('filter')->config['condition'], 'a key named after the node is left alone');
        $this->assertSame(['X-Id' => "{{ nodes['lookup'].output.id }}", 'X-Raw' => '{{ nodes["lookup"]?.output }}'], $graph->node('fetch_2')->config['headers']);
        $this->assertSame('{{ nodes.fetch_2.output.url }}', $graph->node('fetch_2')->config['url'], 'a longer id that starts the same is left alone');
        $this->assertSame([], app(GraphValidator::class)->validate($graph));

        $editor->call('undo');
        $this->assertTrue($workflow->refresh()->draft()->has('fetch'));
    }

    public function test_a_node_id_must_be_free_and_valid_and_triggers_keep_theirs(): void
    {
        [$workspace, $workflow, $user] = $this->setUpEditor();
        $original = $workflow->draft_graph;
        $editor = $this->editor($workspace, $workflow, $user)->call('selectNode', 'fetch')->call('startEditingNodeId');

        $editor->set('newNodeId', 'check')->call('saveNodeId')->assertHasErrors('newNodeId')->assertSee('Another node already uses the ID check.');
        $editor->set('newNodeId', '2fetch')->call('saveNodeId')->assertHasErrors('newNodeId');
        $editor->set('newNodeId', 'fetch-order')->call('saveNodeId')->assertHasErrors('newNodeId');
        $editor->set('newNodeId', str_repeat('a', 65))->call('saveNodeId')->assertHasErrors('newNodeId');
        $editor->call('cancelEditingNodeId')->assertSet('editingNodeId', false)->assertHasNoErrors();

        $editor->call('selectNode', 'start')
            ->assertSeeHtml('data-test="trigger-reference"')
            ->assertDontSeeHtml('data-test="node-id-edit"')
            ->set('newNodeId', 'begin')
            ->call('saveNodeId')
            ->assertHasErrors('newNodeId');

        $this->assertSame($original, $workflow->refresh()->draft_graph);
    }

    public function test_viewers_cannot_change_a_node_id(): void
    {
        [$workspace, $workflow] = $this->setUpEditor();
        $viewer = User::factory()->memberOf($workspace, WorkspaceRole::Viewer)->create();

        $this->editor($workspace, $workflow, $viewer)
            ->call('selectNode', 'fetch')
            ->assertDontSeeHtml('data-test="node-id-edit"')
            ->set('newNodeId', 'lookup')
            ->call('saveNodeId')
            ->assertForbidden();

        $this->assertTrue($workflow->refresh()->draft()->has('fetch'));
    }
}
