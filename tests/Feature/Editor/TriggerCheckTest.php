<?php

namespace Tests\Feature\Editor;

use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class TriggerCheckTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'example.com' ? ['93.184.216.34'] : parent::resolve($host);
            }
        });
    }

    /**
     * The editor with the feed trigger selected.
     *
     * @param  array<string, string|array{0: string, 1?: array<string, mixed>}>  $extra  more nodes, before the feed
     */
    private function editor(array $config, array $extra = [], WorkspaceRole $role = WorkspaceRole::Editor): Testable
    {
        $workflow = Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(
            [...$extra, 'feed' => ['core.new_feed_item', ['url' => 'https://example.com/api', 'format' => 'json', 'id_path' => 'id', ...$config]], 'next' => ['core.compose', ['template' => 'x']]],
            ['feed->next'],
        )]);

        return Livewire::actingAs(User::factory()->memberOf($this->workspace, $role)->create())
            ->test('pages::workflows.edit', ['workspace' => $this->workspace, 'workflow' => $workflow])
            ->call('selectNode', 'feed');
    }

    public function test_check_now_shows_the_newest_items_as_a_run_would_receive_them_and_starts_nothing(): void
    {
        Http::fake(['example.com/api' => [['id' => 3, 'title' => 'Three'], ['id' => 2, 'title' => 'Two'], ['id' => 1, 'title' => 'One'], ['id' => 0, 'title' => 'Zero']]]);

        $editor = $this->editor([])
            ->assertSee(__('workflows.edit.check_now'))
            ->call('checkTrigger')
            ->assertSee('Found 4 items.')
            ->assertSee('Newest item');

        $this->assertSame([
            ['id' => '3', 'item' => ['id' => 3, 'title' => 'Three']],
            ['id' => '2', 'item' => ['id' => 2, 'title' => 'Two']],
            ['id' => '1', 'item' => ['id' => 1, 'title' => 'One']],
        ], $editor->get('triggerCheck.events'));
        $this->assertNull($editor->get('triggerCheck.warning'));
        $this->assertSame(0, Execution::count());
    }

    public function test_check_now_uses_the_settings_in_the_panel_before_they_are_saved(): void
    {
        Http::fake(['example.com/other' => [['ref' => 'a']]]);

        $editor = $this->editor([])
            ->set('form.url', 'https://example.com/other')
            ->set('form.id_path', 'ref')
            ->call('checkTrigger');

        $this->assertSame('a', $editor->get('triggerCheck.events.0.id'));
    }

    public function test_an_item_id_that_matches_nothing_or_only_some_items_is_flagged(): void
    {
        Http::fake(['example.com/api' => Http::sequence()
            ->push([['uuid' => 'a'], ['uuid' => 'b']])
            ->push([['id' => 1], ['uuid' => 'b'], ['uuid' => 'c']])
            ->push([])]);

        $editor = $this->editor([])->call('checkTrigger')
            ->assertSee('None of the 2 items has a value at &quot;id&quot;, so none would start a run. Check Item ID.', false);
        $this->assertSame([], $editor->get('triggerCheck.events'));

        $editor->call('checkTrigger')->assertSee('2 of 3 items have no ID and would be skipped.');
        $editor->call('checkTrigger')->assertSee('The address lists no items right now, so there is nothing to show.');
    }

    public function test_a_list_path_that_is_wrong_or_an_address_that_fails_is_shown_as_an_error(): void
    {
        Http::fake(['example.com/api' => Http::sequence()->push(['data' => []])->push('Nope', 500)]);

        $this->editor(['list_path' => 'items'])
            ->call('checkTrigger')
            ->assertSet('triggerCheck', ['error' => 'There is no list at "items" in the answer.'])
            ->call('checkTrigger')
            ->assertSet('triggerCheck', ['error' => 'The address answered 500 Internal Server Error.']);
    }

    public function test_a_private_address_is_refused_by_check_now_too(): void
    {
        $this->editor(['url' => 'http://127.0.0.1/api'])->call('checkTrigger');

        Http::assertNothingSent();
    }

    public function test_an_item_can_become_the_test_sample_when_test_runs_start_at_this_trigger(): void
    {
        Http::fake(['example.com/api' => [['id' => 7, 'total' => 70]]]);

        $editor = $this->editor([])->call('checkTrigger')
            ->assertSee(__('workflows.edit.use_as_test_sample'))
            ->call('useCheckedItemAsTestSample', 0);

        $this->assertSame(['id' => '7', 'item' => ['id' => 7, 'total' => 70]], json_decode($editor->get('testPayload'), true));
    }

    public function test_no_test_sample_is_offered_when_test_runs_start_at_another_trigger(): void
    {
        Http::fake(['example.com/api' => [['id' => 7]]]);

        $this->editor([], ['start' => 'core.manual_trigger'])
            ->call('checkTrigger')
            ->assertDontSee(__('workflows.edit.use_as_test_sample'))
            ->call('useCheckedItemAsTestSample', 0)
            ->assertSet('testPayload', '');
    }

    public function test_viewers_cannot_check(): void
    {
        $this->editor([], role: WorkspaceRole::Viewer)->call('checkTrigger')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_selecting_another_node_clears_the_result(): void
    {
        Http::fake(['example.com/api' => [['id' => 1]]]);

        $this->editor([])->call('checkTrigger')->call('selectNode', 'next')->assertSet('triggerCheck', null)
            ->assertDontSee(__('workflows.edit.check_now'));
    }
}
