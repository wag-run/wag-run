<?php

namespace Tests\Feature\Integrations;

use App\Enums\WorkspaceRole;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class GitHubTriggersTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();
        Credential::factory()->for($this->workspace)->oauth('github')->create(['id' => $this->settingId('github'), 'description' => 'github']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function publish(string $type, array $config = [], ?Workflow $workflow = null): Workflow
    {
        $graph = $this->graph(['on' => [$type, ['credential' => $this->settingId('github'), 'repository' => 'acme/app', 'listen' => 'poll', ...$config]]]);
        $workflow ??= Workflow::factory()->for($this->workspace)->create();
        $workflow->update(['draft_graph' => $graph]);
        app(Workflows::class)->publish($workflow->refresh());

        return $workflow->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(WorkflowTrigger $trigger, string $event, array $payload, ?string $secret = null): TestResponse
    {
        $body = json_encode(['repository' => ['full_name' => 'acme/app'], ...$payload]);

        return $this->call('POST', route('integrations.events', $trigger->key), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $trigger->refresh()->state['secret']),
        ], content: $body);
    }

    public function test_a_polling_trigger_records_existing_tags_then_starts_a_run_per_new_one(): void
    {
        $workflow = $this->publish('github.new_tag', ['poll_minutes' => 5]);
        $trigger = $workflow->triggers()->sole();
        $this->assertSame(WorkflowTrigger::POLL, $trigger->type);

        $both = [['name' => 'v1.2', 'commit' => ['sha' => 'ccc']], ['name' => 'v1.1', 'commit' => ['sha' => 'bbb']], ['name' => 'v1.0', 'commit' => ['sha' => 'aaa']]];
        Http::fake(['api.github.com/repos/acme/app/tags*' => Http::sequence()
            ->push([['name' => 'v1.0', 'commit' => ['sha' => 'aaa']]], 200, ['ETag' => '"one"'])
            ->push($both)
            ->push($both)]);

        $this->artisan('workflows:poll-triggers')->assertSuccessful();
        $this->assertSame(0, Execution::count(), 'the first poll only records what exists');

        $this->artisan('workflows:poll-triggers');
        $this->assertSame(1, Http::recorded()->count(), 'not due again for five minutes');

        $this->travel(5)->minutes();
        $this->artisan('workflows:poll-triggers');

        $runs = Execution::query()->orderBy('id')->get();
        $this->assertSame(['v1.1', 'v1.2'], $runs->pluck('input.tag')->all());
        $this->assertSame('event', $runs[0]->trigger_type);
        $this->assertSame(['tag' => 'v1.1', 'sha' => 'bbb', 'repository' => 'acme/app', 'url' => 'https://github.com/acme/app/tree/v1.1'], $runs[0]->input);
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer gho_access']);
        Http::assertNotSent(fn (Request $request) => $request->hasHeader('If-None-Match'));

        $this->travel(5)->minutes();
        $this->artisan('workflows:poll-triggers');
        $this->assertSame(2, Execution::count(), 'an unchanged answer starts nothing');
    }

    /**
     * Answer the tags endpoint from a list that tests change between polls,
     * 100 a page with GitHub's rel="next" link while more follow.
     *
     * @param  list<string>  $tags
     */
    private function fakeTags(array &$tags): void
    {
        Http::fake(['api.github.com/repos/acme/app/tags*' => function (Request $request) use (&$tags) {
            $page = (int) ($request['page'] ?? 1);
            $link = count($tags) > $page * 100 ? '<https://api.github.com/repos/acme/app/tags?per_page=100&page='.($page + 1).'>; rel="next"' : '';

            return Http::response(
                array_map(fn (string $tag) => ['name' => $tag, 'commit' => ['sha' => "sha-{$tag}"]], array_slice($tags, ($page - 1) * 100, 100)),
                200,
                $link === '' ? [] : ['Link' => $link],
            );
        }]);
    }

    private function poll(): void
    {
        $this->artisan('workflows:poll-triggers')->assertSuccessful();
        $this->travel(5)->minutes();
    }

    public function test_new_tag_reads_every_page_so_a_new_tag_on_a_later_page_starts_a_run(): void
    {
        $this->publish('github.new_tag', ['poll_minutes' => 5]);
        $tags = array_map(fn (int $i) => "v{$i}.0", range(250, 1, -1));
        $this->fakeTags($tags);

        $this->poll();
        $this->assertSame(0, Execution::count());
        $this->assertSame(3, Http::recorded()->count(), 'three pages of tags');

        array_splice($tags, 230, 0, ['v0.9']);
        $this->poll();

        $this->assertSame(['v0.9'], Execution::pluck('input')->pluck('tag')->all());
    }

    public function test_new_tag_remembers_every_tag_it_read_so_none_looks_new_again(): void
    {
        $this->publish('github.new_tag', ['poll_minutes' => 5]);
        $tags = array_map(fn (int $i) => "v{$i}", range(1, 350));
        $this->fakeTags($tags);

        $this->poll();
        $this->poll();
        $this->poll();

        $this->assertSame(0, Execution::count());
    }

    public function test_a_tag_trigger_that_only_read_the_first_page_starts_no_runs_on_its_first_full_read(): void
    {
        $trigger = $this->publish('github.new_tag', ['poll_minutes' => 5])->triggers()->sole();
        $tags = array_map(fn (int $i) => "v{$i}", range(1, 150));
        $trigger->forceFill(['state' => [...$trigger->state, 'memory' => ['seen' => array_slice($tags, 0, 100), 'etag' => '"old"']]])->save();
        $this->fakeTags($tags);

        $this->poll();
        $this->assertSame(0, Execution::count(), 'tags past the first page are not taken for new');

        $tags[] = 'v151';
        $this->poll();
        $this->assertSame(['v151'], Execution::pluck('input')->pluck('tag')->all());
    }

    public function test_a_release_poll_sends_the_last_etag_and_an_unchanged_answer_starts_nothing(): void
    {
        $trigger = $this->publish('github.new_release', ['poll_minutes' => 5])->triggers()->sole();
        $one = ['id' => 1, 'tag_name' => 'v1', 'draft' => false, 'prerelease' => false];
        Http::fake(['api.github.com/repos/acme/app/releases*' => Http::sequence()
            ->push([$one], 200, ['ETag' => '"one"'])
            ->push(null, 304)
            ->push([['id' => 2, 'tag_name' => 'v2', 'draft' => false, 'prerelease' => false], $one], 200, ['ETag' => '"two"'])]);

        $this->poll();
        $this->poll();

        Http::assertSent(fn (Request $request) => $request->header('If-None-Match') === ['"one"']);
        $this->assertSame(0, Execution::count());
        $this->assertNull($trigger->refresh()->state['error'], 'an unchanged answer is not a failed poll');

        $this->poll();
        $this->assertSame(['v2'], Execution::pluck('input')->pluck('tag')->all(), 'what was seen before the unchanged answer is still known');
    }

    public function test_a_failed_poll_is_noted_on_the_trigger(): void
    {
        $workflow = $this->publish('github.new_release');
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->artisan('workflows:poll-triggers');

        $this->assertSame('GitHub answered 404: Not Found', $workflow->triggers()->sole()->state['error']);

        $user = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $this->actingAs($user)->get(route('workspaces.workflows.edit', [$this->workspace, $workflow]))
            ->assertOk()
            ->assertSee('Not working: GitHub answered 404: Not Found');
    }

    public function test_a_webhook_trigger_registers_a_hook_and_starts_runs_for_matching_deliveries(): void
    {
        Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201)]);

        $workflow = $this->publish('github.new_tag', ['listen' => 'webhook']);
        $trigger = $workflow->triggers()->sole();

        $this->assertSame(WorkflowTrigger::SUBSCRIPTION, $trigger->type);
        $this->assertSame(['hook_id' => 77], $trigger->state['subscription']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['events'] === ['push']
            && $request['config']['url'] === route('integrations.events', $trigger->key)
            && $request['config']['secret'] === $trigger->state['secret']);

        $this->deliver($trigger, 'ping', ['zen' => 'Keep it simple'])->assertAccepted()->assertJson(['execution_ids' => []]);
        $this->deliver($trigger, 'push', ['ref' => 'refs/heads/main', 'created' => false, 'after' => 'x'])->assertAccepted();
        $this->deliver($trigger, 'push', ['ref' => 'refs/tags/v2.0', 'created' => true, 'deleted' => false, 'after' => 'abc'])->assertAccepted();
        $this->deliver($trigger, 'push', ['ref' => 'refs/tags/v3.0', 'created' => true, 'after' => 'def'], secret: 'wrong')->assertForbidden();

        $this->assertSame(['v2.0'], Execution::pluck('input')->pluck('tag')->all());
    }

    public function test_changing_or_deleting_a_webhook_trigger_removes_its_hook(): void
    {
        Http::fake([
            'api.github.com/repos/acme/app/hooks' => Http::sequence()->push(['id' => 77], 201)->push(['id' => 78], 201),
            'api.github.com/repos/acme/app/hooks/*' => Http::response(null, 204),
        ]);
        $workflow = $this->publish('github.new_release', ['listen' => 'webhook']);

        $this->publish('github.new_release', ['listen' => 'webhook', 'include_prereleases' => false], $workflow);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/77'));
        $this->assertSame(['hook_id' => 78], $workflow->triggers()->sole()->state['subscription']);

        app(Workflows::class)->publish($workflow->refresh());
        $this->assertSame(['hook_id' => 78], $workflow->triggers()->sole()->state['subscription'], 'republishing unchanged keeps the hook');

        app(Workflows::class)->delete($workflow);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/78'));
    }

    public function test_a_hook_is_registered_at_app_url_whatever_host_the_install_was_reached_by(): void
    {
        config(['app.url' => 'https://wag.example.com']);
        URL::forceRootUrl('http://192.168.1.20:8080');
        Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201)]);

        $trigger = $this->publish('github.new_tag', ['listen' => 'webhook'])->triggers()->sole();

        $url = "https://wag.example.com/integrations/events/{$trigger->key}";
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['config']['url'] === $url);
        $this->assertSame($url, $trigger->state['url']);
    }

    public function test_republishing_after_app_url_changes_moves_the_hook_registering_the_new_one_before_removing_the_old(): void
    {
        config(['app.url' => 'https://old.example.com']);
        Http::fake([
            'api.github.com/repos/acme/app/hooks' => Http::sequence()->push(['id' => 77], 201)->push(['id' => 78], 201),
            'api.github.com/repos/acme/app/hooks/*' => Http::response(null, 204),
        ]);
        $workflow = $this->publish('github.new_tag', ['listen' => 'webhook']);
        $trigger = $workflow->triggers()->sole();
        $oldSecret = $trigger->state['secret'];

        config(['app.url' => 'https://new.example.com']);
        app(Workflows::class)->publish($workflow->refresh());

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->method().' '.($pair[0]['config']['url'] ?? $pair[0]->url()))->all();
        $this->assertSame([
            "POST https://old.example.com/integrations/events/{$trigger->key}",
            "POST https://new.example.com/integrations/events/{$trigger->key}",
            'DELETE https://api.github.com/repos/acme/app/hooks/77',
        ], $requests);

        $state = $trigger->refresh()->state;
        $this->assertSame(['hook_id' => 78], $state['subscription']);
        $this->assertSame("https://new.example.com/integrations/events/{$trigger->key}", $state['url']);
        $this->assertNotSame($oldSecret, $state['secret']);
        $this->deliver($trigger, 'push', ['ref' => 'refs/tags/v2.0', 'created' => true, 'after' => 'abc'])->assertAccepted();

        app(Workflows::class)->publish($workflow->refresh());
        $this->assertCount(3, Http::recorded(), 'a hook already at APP_URL is left alone');
    }

    public function test_a_hook_that_cannot_be_moved_keeps_the_old_one_and_is_tried_again_on_the_next_publish(): void
    {
        config(['app.url' => 'https://old.example.com']);
        Http::fake([
            'api.github.com/repos/acme/app/hooks' => Http::sequence()
                ->push(['id' => 77], 201)
                ->push(['message' => 'Not Found'], 404)
                ->push(['id' => 78], 201),
            'api.github.com/repos/acme/app/hooks/*' => Http::response(null, 204),
        ]);
        $workflow = $this->publish('github.new_tag', ['listen' => 'webhook']);

        config(['app.url' => 'https://new.example.com']);
        app(Workflows::class)->publish($workflow->refresh());

        $state = $workflow->triggers()->sole()->state;
        $this->assertSame(['hook_id' => 77], $state['subscription']);
        $this->assertSame('GitHub answered 404: Not Found', $state['error']);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');

        app(Workflows::class)->publish($workflow->refresh());

        $this->assertSame(['hook_id' => 78], $workflow->triggers()->sole()->state['subscription']);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/hooks/77'));
    }

    public function test_a_hook_registered_before_its_url_was_recorded_is_registered_again_once(): void
    {
        Http::fake([
            'api.github.com/repos/acme/app/hooks' => Http::sequence()->push(['id' => 77], 201)->push(['id' => 78], 201),
            'api.github.com/repos/acme/app/hooks/*' => Http::response(null, 204),
        ]);
        $workflow = $this->publish('github.new_tag', ['listen' => 'webhook']);
        $trigger = $workflow->triggers()->sole();
        $state = $trigger->state;
        unset($state['url']);
        $trigger->forceFill(['state' => $state])->save();

        app(Workflows::class)->publish($workflow->refresh());
        app(Workflows::class)->publish($workflow->refresh());

        $this->assertSame(['hook_id' => 78], $trigger->refresh()->state['subscription']);
        $this->assertCount(3, Http::recorded());
    }

    public function test_a_hook_github_refuses_is_noted_and_retried_on_the_next_publish(): void
    {
        Http::fake(['api.github.com/repos/acme/app/hooks' => Http::sequence()
            ->push(['message' => 'Not Found'], 404)
            ->push(['id' => 90], 201)]);

        $workflow = $this->publish('github.workflow_run_failed', ['listen' => 'webhook']);
        $this->assertSame('GitHub answered 404: Not Found', $workflow->triggers()->sole()->state['error']);

        app(Workflows::class)->publish($workflow);
        $this->assertSame(['hook_id' => 90], $workflow->triggers()->sole()->state['subscription']);
    }

    public function test_release_and_failed_run_triggers_apply_their_filters(): void
    {
        Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 1], 201)]);
        $releases = $this->publish('github.new_release', ['listen' => 'webhook', 'include_prereleases' => false])->triggers()->sole();
        $failures = $this->publish('github.workflow_run_failed', ['listen' => 'webhook', 'workflow' => 'ci.yml', 'branch' => 'main'])->triggers()->sole();

        $this->deliver($releases, 'release', ['action' => 'published', 'release' => ['tag_name' => 'v2-rc', 'prerelease' => true]]);
        $this->deliver($releases, 'release', ['action' => 'published', 'release' => ['tag_name' => 'v2', 'name' => 'Two', 'html_url' => 'https://github.com/acme/app/releases/v2', 'author' => ['login' => 'octocat']]]);
        $this->deliver($failures, 'workflow_run', ['action' => 'completed', 'workflow_run' => ['id' => 5, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'head_branch' => 'feature', 'conclusion' => 'failure']]);
        $this->deliver($failures, 'workflow_run', ['action' => 'completed', 'workflow_run' => ['id' => 6, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'conclusion' => 'success']]);
        $this->deliver($failures, 'workflow_run', ['action' => 'completed', 'workflow_run' => ['id' => 7, 'name' => 'CI', 'path' => '.github/workflows/ci.yml', 'head_branch' => 'main', 'conclusion' => 'failure', 'actor' => ['login' => 'octocat']]]);
        $this->deliver($failures, 'workflow_run', ['action' => 'completed', 'workflow_run' => ['id' => 8, 'name' => 'Deploy', 'path' => '.github/workflows/deploy.yml', 'head_branch' => 'main', 'conclusion' => 'failure']]);

        $inputs = Execution::query()->orderBy('id')->pluck('input')->all();
        $this->assertCount(2, $inputs);
        $this->assertSame(['v2', 'Two', 'octocat'], [$inputs[0]['tag'], $inputs[0]['name'], $inputs[0]['author']]);
        $this->assertSame([7, 'main', 'octocat'], [$inputs[1]['id'], $inputs[1]['branch'], $inputs[1]['actor']]);
    }

    public function test_publishing_refuses_a_repository_that_isnt_owner_and_name(): void
    {
        $workflow = Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(['on' => ['github.new_tag', ['credential' => $this->settingId('github'), 'repository' => 'not a repo']]])]);

        $this->expectException(ValidationException::class);
        app(Workflows::class)->publish($workflow);
    }
}
