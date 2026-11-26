<?php

namespace Tests\Feature\Integrations;

use App\Models\Credential;
use App\Models\Execution;
use App\Models\Variable;
use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use App\Models\Workspace;
use App\Services\Workflows;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class FeedTriggerTest extends TestCase
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
                return match ($host) {
                    'example.com' => ['93.184.216.34'],
                    'cdn.example.net' => ['93.184.216.35'],
                    default => parent::resolve($host),
                };
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function publish(array $config = []): Workflow
    {
        $workflow = Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(['on' => ['core.new_feed_item', ['url' => 'https://example.com/feed.xml', ...$config]]])]);
        app(Workflows::class)->publish($workflow);

        return $workflow->refresh();
    }

    private function poll(): void
    {
        $this->travel(15)->minutes();
        $this->artisan('workflows:poll-triggers')->assertSuccessful();
    }

    /**
     * @param  list<string>  $items
     */
    private function rss(array $items): string
    {
        return '<?xml version="1.0"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel><title>Blog</title>'.implode('', $items).'</channel></rss>';
    }

    private function entry(string $guid, string $title): string
    {
        return "<item><guid>{$guid}</guid><title>{$title}</title><link>https://example.com/{$guid}</link><description><![CDATA[<p>About &amp; <b>{$title}</b></p>]]></description><dc:creator>Ada</dc:creator><pubDate>Tue, 24 Nov 2026 10:00:00 +0000</pubDate></item>";
    }

    public function test_records_existing_entries_then_starts_a_run_per_new_one_oldest_first(): void
    {
        $workflow = $this->publish();
        $this->assertSame(WorkflowTrigger::POLL, $workflow->triggers()->sole()->type);

        Http::fake(['example.com/feed.xml' => Http::sequence()
            ->push($this->rss([$this->entry('p1', 'One')]))
            ->push($this->rss([$this->entry('p3', 'Three'), $this->entry('p2', 'Two'), $this->entry('p1', 'One')]))
            ->push($this->rss([$this->entry('p3', 'Three'), $this->entry('p2', 'Two'), $this->entry('p1', 'One')]))]);

        $this->artisan('workflows:poll-triggers')->assertSuccessful();
        $this->assertSame(0, Execution::count(), 'the first poll only records what exists');

        $this->poll();

        $runs = Execution::query()->orderBy('id')->get();
        $this->assertSame(['p2', 'p3'], $runs->pluck('input.id')->all());
        $this->assertSame('event', $runs[0]->trigger_type);
        $this->assertSame([
            'id' => 'p2', 'title' => 'Two', 'link' => 'https://example.com/p2', 'summary' => 'About & Two', 'author' => 'Ada', 'published_at' => '2026-11-24T10:00:00+00:00',
        ], $runs[0]->input);

        $this->poll();
        $this->assertSame(2, Execution::count(), 'entries already seen start nothing');
    }

    public function test_reads_atom_entries_with_their_alternate_link_author_name_and_updated_date(): void
    {
        $this->publish();
        $atom = fn (string $entries) => '<feed xmlns="http://www.w3.org/2005/Atom"><title>Changelog</title>'.$entries.'</feed>';
        Http::fake(['example.com/feed.xml' => Http::sequence()->push($atom(''))->push($atom(
            '<entry><id>tag:example.com,2026:1</id><title>Shipped</title><link rel="self" href="https://example.com/self"/><link rel="alternate" href="https://example.com/1"/>'
            .'<summary>Short</summary><author><name>Grace</name></author><updated>2026-11-25T08:30:00Z</updated></entry>'
            .'<entry><title>No id, link only</title><link href="https://example.com/2"/></entry>'
            .'<entry><title>Nothing to identify it</title></entry>'
        ))]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();

        $runs = Execution::query()->orderBy('id')->get();
        $this->assertSame(['https://example.com/2', 'tag:example.com,2026:1'], $runs->pluck('input.id')->all());
        $this->assertSame(
            ['id' => 'tag:example.com,2026:1', 'title' => 'Shipped', 'link' => 'https://example.com/1', 'summary' => 'Short', 'author' => 'Grace', 'published_at' => '2026-11-25T08:30:00+00:00'],
            $runs[1]->input,
        );
    }

    public function test_a_json_list_starts_a_run_per_new_item_with_the_whole_item(): void
    {
        $this->publish(['url' => 'https://example.com/api/orders', 'format' => 'json', 'list_path' => 'data.items', 'id_path' => 'ref.id', 'newest_first' => false]);
        $item = fn (int $id) => ['ref' => ['id' => $id], 'total' => $id * 10, 'nothing' => ['no id here']];
        Http::fake(['example.com/api/orders' => Http::sequence()
            ->push(['data' => ['items' => [$item(1)]]])
            ->push(['data' => ['items' => [$item(1), $item(2), ['total' => 5], $item(3)]]])]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();

        $runs = Execution::query()->orderBy('id')->get();
        $this->assertSame(['2', '3'], $runs->pluck('input.id')->all(), 'a list that starts with the oldest is read in order, and an item without an ID is skipped');
        $this->assertSame($item(2), $runs[0]->input['item']);
    }

    public function test_a_top_level_json_array_is_read_newest_first_by_default(): void
    {
        $this->publish(['format' => 'json', 'id_path' => 'id']);
        Http::fake(['example.com/feed.xml' => Http::sequence()->push([['id' => 'a']])->push([['id' => 'c'], ['id' => 'b'], ['id' => 'a']])]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();

        $this->assertSame(['b', 'c'], Execution::query()->orderBy('id')->get()->pluck('input.id')->all());
    }

    public function test_the_address_and_headers_can_read_variables_and_authentication_is_applied(): void
    {
        Variable::factory()->for($this->workspace)->create(['key' => 'feed_host', 'value' => 'example.com']);
        $credential = Credential::factory()->for($this->workspace)->create(['type' => 'api_token', 'description' => 'feed token', 'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 's3cret']]);
        $this->publish(['url' => 'https://{{ vars.feed_host }}/feed.xml', 'credential' => $credential->id, 'headers' => ['X-Site' => '{{ vars.feed_host }}']]);
        Http::fake(['example.com/feed.xml' => $this->rss([])]);

        $this->artisan('workflows:poll-triggers');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/feed.xml'
            && $request->header('Authorization') === ['Bearer s3cret']
            && $request->header('X-Site') === ['example.com']);
    }

    public function test_a_redirect_to_another_host_is_followed_without_the_authentication_or_headers(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['type' => 'api_token', 'description' => 'feed token', 'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 's3cret']]);
        $this->publish(['credential' => $credential->id, 'headers' => ['X-Site' => 'mine']]);
        Http::fake([
            'example.com/feed.xml' => Http::response('', 302, ['Location' => 'https://cdn.example.net/feed.xml']),
            'cdn.example.net/feed.xml' => $this->rss([$this->entry('p1', 'One')]),
        ]);

        $workflow = Workflow::query()->sole();
        $this->artisan('workflows:poll-triggers');

        $this->assertNull($workflow->triggers()->sole()->state['error']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://cdn.example.net/feed.xml' && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Site'));
    }

    public function test_a_private_address_is_refused_unless_the_workspace_allows_it(): void
    {
        $workflow = $this->publish(['url' => 'http://127.0.0.1/feed.xml']);
        Http::fake(['127.0.0.1/feed.xml' => $this->rss([])]);

        $this->artisan('workflows:poll-triggers');
        $this->assertStringContainsString('127.0.0.1', $workflow->triggers()->sole()->state['error']);
        Http::assertNothingSent();

        $this->workspace->update(['internal_allowlist' => ['127.0.0.1']]);
        $this->poll();
        $this->assertNull($workflow->triggers()->sole()->state['error']);
    }

    public function test_a_failed_poll_is_noted_on_the_trigger_and_keeps_what_it_had_seen(): void
    {
        $workflow = $this->publish();
        Http::fake(['example.com/feed.xml' => Http::sequence()
            ->push($this->rss([$this->entry('p1', 'One')]))
            ->push('Gone', 503)
            ->push('<html>Not a feed</html>')
            ->push('<!DOCTYPE rss [<!ENTITY x "boom">]><rss><channel><item><guid>&x;</guid></item></channel></rss>')
            ->push($this->rss([$this->entry('p1', 'One')]))]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();
        $this->assertSame('The feed answered 503 Service Unavailable.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertSame('The address did not return an RSS or Atom feed.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertSame('The address did not return an RSS or Atom feed.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertNull($workflow->triggers()->sole()->state['error']);
        $this->assertSame(0, Execution::count());
    }

    public function test_json_settings_that_do_not_match_the_answer_are_reported(): void
    {
        $workflow = $this->publish(['format' => 'json', 'list_path' => 'data', 'id_path' => 'id']);
        Http::fake(['example.com/feed.xml' => Http::sequence()->push(['other' => []])->push('not json')->push(['data' => ['id' => 1]])]);

        $this->artisan('workflows:poll-triggers');
        $this->assertSame('There is no list at "data" in the answer.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertSame('The address did not return JSON.', $workflow->triggers()->sole()->state['error']);

        $this->poll();
        $this->assertSame('There is no list at "data" in the answer.', $workflow->triggers()->sole()->state['error']);
    }

    public function test_a_long_feed_is_not_started_again_because_the_memory_ran_out(): void
    {
        $this->publish();
        $posts = fn () => array_map(fn (int $number) => $this->entry("p{$number}", "Post {$number}"), range(1, 400));
        Http::fake(['example.com/feed.xml' => $this->rss($posts())]);

        $this->artisan('workflows:poll-triggers');
        $this->poll();

        $this->assertSame(0, Execution::count());
    }

    public function test_a_json_list_without_an_item_id_cant_be_published(): void
    {
        $this->expectException(ValidationException::class);

        $this->publish(['format' => 'json']);
    }
}
