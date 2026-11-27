<?php

namespace Tests\Feature\Engine;

use App\Engine\Redactor;
use App\Enums\StepStatus;
use App\Models\Credential;
use App\Models\Secret;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class MaskingTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_secret_and_credential_values_are_masked_before_step_rows_are_written(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        Credential::factory()->for($workspace)->create(['config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'ghp_tokenvalue']]);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'leak' => ['emit', ['value' => 'key=sk_live_supersecret token=ghp_tokenvalue']]],
            ['start->leak'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow, ['password' => 'sk_live_supersecret']);

        $leak = $this->stepFor($execution, 'leak');
        $masked = 'key='.Redactor::MASK.' token='.Redactor::MASK;
        $this->assertSame($masked, $leak->input['value']);
        $this->assertSame($masked, $leak->output['value']);
        $this->assertSame(['password' => Redactor::MASK], $execution->input);

        $rows = json_encode($execution->steps()->get(['input', 'output'])->toArray()).json_encode($execution->withoutRelations()->toArray());
        $this->assertStringNotContainsString('sk_live_supersecret', $rows);
        $this->assertStringNotContainsString('ghp_tokenvalue', $rows);
    }

    public function test_short_values_and_other_workspaces_secrets_are_not_masked(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => '42']);
        Secret::factory()->for(Workspace::factory())->create(['value' => 'other-workspace-secret']);

        $redactor = Redactor::forWorkspace($workspace);

        $this->assertSame(['n' => 'answer 42', 'x' => 'other-workspace-secret'], $redactor->mask(['n' => 'answer 42', 'x' => 'other-workspace-secret']));
    }

    public function test_a_secret_among_the_items_a_loop_goes_through_is_masked_in_the_state_the_loop_saves(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['core.for_loop', ['items' => '["key=sk_live_supersecret", "plain"]']]],
            ['start->each'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['key='.Redactor::MASK, 'plain'], $this->stepFor($execution, 'each')->state['items']);
    }

    public function test_a_secret_a_failed_step_returns_in_its_output_is_masked(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.example.com/charge' => Http::response('Invalid key sk_live_supersecret', 401)]);
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['core.http_request', ['method' => 'POST', 'url' => 'https://api.example.com/charge']]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $call = $this->stepFor($execution, 'call');
        $this->assertSame(StepStatus::Failed, $call->status);
        $this->assertSame('Invalid key '.Redactor::MASK, $call->output['body']);
    }

    public function test_a_secret_in_what_a_terminate_node_ends_the_run_with_is_masked_on_the_step_and_the_run(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'stop' => ['core.terminate', ['status' => 'failed', 'output' => 'Refused key sk_live_supersecret']]],
            ['start->stop'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('Refused key '.Redactor::MASK, $this->stepFor($execution, 'stop')->output);
        $this->assertSame('Refused key '.Redactor::MASK, $execution->output);
    }

    public function test_a_secret_that_contains_another_secret_is_masked_whole(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'prefix', 'value' => 'tok_live']);
        Secret::factory()->for($workspace)->create(['key' => 'token', 'value' => 'tok_live_9f8e7d']);

        $redactor = Redactor::forWorkspace($workspace);

        $this->assertSame('key '.Redactor::MASK.' and '.Redactor::MASK, $redactor->mask('key tok_live_9f8e7d and tok_live'));
    }
}
