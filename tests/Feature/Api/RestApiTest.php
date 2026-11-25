<?php

namespace Tests\Feature\Api;

use App\Enums\ExecutionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WorkflowApi;
use App\Support\RunChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class RestApiTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
        $this->workspace = Workspace::factory()->create(['name' => 'Ops']);
        $this->workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.manual_trigger', ['fields' => [['name' => 'order', 'label' => 'Order', 'type' => 'number', 'required' => true]]]], 'wait' => 'pause'],
            ['start->wait'],
        ), $this->workspace);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function actingAsMember(WorkspaceRole $role = WorkspaceRole::Editor, array $scopes = ['workflows:read', 'runs:read', 'runs:write']): User
    {
        $user = User::factory()->memberOf($this->workspace, $role)->create();
        Passport::actingAs($user, $scopes);

        return $user;
    }

    private function url(string $path): string
    {
        return "/api/v1/workspaces/{$this->workspace->slug}/{$path}";
    }

    public function test_requests_without_a_token_are_refused(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    }

    public function test_lists_the_workspaces_and_workflows_with_their_inputs(): void
    {
        $this->actingAsMember();
        Workspace::factory()->create(['name' => 'Elsewhere']);

        $this->getJson('/api/v1/workspaces')->assertOk()->assertExactJson(['data' => [
            ['slug' => $this->workspace->slug, 'name' => 'Ops', 'url' => route('workspaces.show', $this->workspace)],
        ]]);

        $this->getJson($this->url('workflows'))->assertOk()
            ->assertJsonPath('data.0.id', $this->workflow->id)
            ->assertJsonPath('data.0.published_version', 1)
            ->assertJsonPath('data.0.inputs', [['name' => 'order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'options' => null]]);
    }

    public function test_a_workflow_comes_with_its_definition_and_a_list_only_when_asked(): void
    {
        $this->actingAsMember(WorkspaceRole::Viewer, ['workflows:read']);
        $this->workflow->update(['description' => 'Waits for an order']);

        $definition = $this->getJson($this->url("workflows/{$this->workflow->id}"))->assertOk()->json('data.definition');

        $this->assertSame(['wag.run/workflow', $this->workflow->name, 'Waits for an order'], [$definition['format'], $definition['name'], $definition['description']]);
        $this->assertSame(['start', 'wait'], array_column($definition['graph']['nodes'], 'id'));
        $this->getJson($this->url('workflows'))->assertOk()->assertJsonMissingPath('data.0.definition');
        $this->getJson($this->url('workflows?include=definition'))->assertOk()->assertJsonPath('data.0.definition', $definition);
    }

    public function test_starts_a_run_with_validated_input_and_shows_its_steps(): void
    {
        $user = $this->actingAsMember();

        $this->postJson($this->url("workflows/{$this->workflow->id}/runs"), ['input' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('order');

        $id = $this->postJson($this->url("workflows/{$this->workflow->id}/runs"), ['input' => ['order' => '42']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.trigger_type', 'api')
            ->assertJsonPath('data.input', ['values' => ['order' => 42]])
            ->json('data.id');

        $this->assertSame($user->id, Execution::find($id)->triggered_by);
        $this->getJson($this->url("runs/{$id}"))->assertOk()
            ->assertJsonPath('data.workflow.name', $this->workflow->name)
            ->assertJsonPath('data.steps.0.node_id', 'start')
            ->assertJsonPath('data.steps.1.status', 'waiting');
    }

    public function test_lists_runs_with_filters_and_cancels_one(): void
    {
        $this->actingAsMember();
        $waiting = $this->runWorkflow($this->workflow, ['values' => ['order' => 1]]);
        $other = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $this->workspace);
        $this->runWorkflow($other);

        $this->getJson($this->url('runs'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->url("runs?workflow={$this->workflow->id}"))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('runs?status=complete'))->assertOk()->assertJsonPath('data.0.workflow.id', $other->id);
        $this->getJson($this->url('runs?status=nope'))->assertUnprocessable();

        $this->postJson($this->url("runs/{$waiting->id}/cancel"))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(ExecutionStatus::Cancelled, $waiting->refresh()->status);
    }

    public function test_the_token_needs_the_scope_and_the_person_the_role(): void
    {
        $this->actingAsMember(scopes: ['workflows:read']);
        $this->getJson($this->url('runs'))->assertForbidden()->assertJsonPath('message', 'This token doesn\'t have the runs:read scope.');

        $this->actingAsMember(WorkspaceRole::Viewer);
        $this->getJson($this->url('runs'))->assertOk();
        $this->postJson($this->url("workflows/{$this->workflow->id}/runs"), ['input' => ['order' => 1]])->assertForbidden();
    }

    public function test_other_workspaces_and_unknown_ids_are_not_found(): void
    {
        $this->actingAsMember();
        $foreign = Workspace::factory()->create();
        $foreignRun = $this->runWorkflow($this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $foreign));

        $this->getJson("/api/v1/workspaces/{$foreign->slug}/runs")->assertNotFound();
        $this->getJson($this->url("runs/{$foreignRun->id}"))->assertNotFound();
        $this->getJson($this->url('runs/not-a-uuid'))->assertNotFound();
        $this->getJson($this->url('workflows/not-a-uuid'))->assertNotFound();
    }

    public function test_deactivated_people_are_refused(): void
    {
        $user = User::factory()->deactivated()->create();
        Passport::actingAs($user, ['workflows:read']);

        $this->getJson('/api/v1/workspaces')->assertForbidden();
    }

    public function test_a_run_started_by_one_of_this_installs_runs_continues_its_chain(): void
    {
        $this->actingAsMember();
        $parent = $this->runWorkflow($this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $this->workspace));

        $id = $this->postJson($this->url("workflows/{$this->workflow->id}/runs"), ['input' => ['order' => 1]], [RunChain::HEADER => RunChain::headerFor($parent)])->json('data.id');

        $this->assertSame(2, Execution::find($id)->chain_depth);
    }

    public function test_mcp_tokens_can_use_every_endpoint(): void
    {
        $this->actingAsMember(scopes: [WorkflowApi::SCOPE_MCP]);

        $this->postJson($this->url("workflows/{$this->workflow->id}/runs"), ['input' => ['order' => 1]])->assertCreated();
    }
}
