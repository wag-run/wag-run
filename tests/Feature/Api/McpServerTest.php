<?php

namespace Tests\Feature\Api;

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\CancelRun;
use App\Mcp\Tools\GetRun;
use App\Mcp\Tools\GetWorkflow;
use App\Mcp\Tools\ListRuns;
use App\Mcp\Tools\ListWorkflows;
use App\Mcp\Tools\ListWorkspaces;
use App\Mcp\Tools\StartRun;
use App\Models\Execution;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\WorkflowApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class McpServerTest extends TestCase
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
    private function member(WorkspaceRole $role = WorkspaceRole::Editor, array $scopes = [WorkflowApi::SCOPE_MCP]): User
    {
        $user = User::factory()->memberOf($this->workspace, $role)->create();
        Passport::actingAs($user, $scopes);

        return $user;
    }

    public function test_the_endpoint_asks_mcp_clients_to_authenticate_through_oauth(): void
    {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_finds_a_workflow_and_starts_and_follows_a_run(): void
    {
        $user = $this->member();
        $server = WorkflowServer::actingAs($user);

        $server->tool(ListWorkspaces::class)->assertOk()->assertSee($this->workspace->slug);
        $server->tool(ListWorkflows::class, ['workspace' => $this->workspace->slug])->assertOk()->assertSee([$this->workflow->id, '"name":"order"']);
        $server->tool(GetWorkflow::class, ['workspace' => $this->workspace->slug, 'workflow' => $this->workflow->id])->assertOk()->assertSee(['"published_version":1', '"definition":{']);

        $server->tool(StartRun::class, ['workspace' => $this->workspace->slug, 'workflow' => $this->workflow->id, 'input' => ['order' => 7]])
            ->assertOk()->assertSee(['"status":"waiting"', '"trigger_type":"api"']);
        $run = Execution::sole();
        $this->assertSame($user->id, $run->triggered_by);

        $server->tool(ListRuns::class, ['workspace' => $this->workspace->slug, 'status' => 'waiting'])->assertOk()->assertSee([$run->id, '"last_page":1']);
        $server->tool(GetRun::class, ['workspace' => $this->workspace->slug, 'run' => $run->id])->assertOk()->assertSee('"node_id":"wait"');
        $server->tool(CancelRun::class, ['workspace' => $this->workspace->slug, 'run' => $run->id])->assertOk()->assertSee('"status":"cancelled"');
    }

    public function test_errors_come_back_as_tool_errors(): void
    {
        $server = WorkflowServer::actingAs($this->member(WorkspaceRole::Viewer));

        $server->tool(StartRun::class, ['workspace' => $this->workspace->slug, 'workflow' => $this->workflow->id, 'input' => ['order' => 1]])
            ->assertHasErrors(['Your role in this workspace doesn\'t allow this.']);
        $server->tool(GetRun::class, ['workspace' => $this->workspace->slug, 'run' => 'missing'])
            ->assertHasErrors(['Execution not found, or you can\'t see it.']);
        $server->tool(ListRuns::class, ['workspace' => 'elsewhere'])
            ->assertHasErrors(['Workspace not found, or you can\'t see it.']);
    }

    public function test_a_personal_token_is_limited_to_its_scopes(): void
    {
        $server = WorkflowServer::actingAs($this->member(scopes: [WorkflowApi::SCOPE_RUNS_READ]));

        $server->tool(ListRuns::class, ['workspace' => $this->workspace->slug])->assertOk();
        $server->tool(StartRun::class, ['workspace' => $this->workspace->slug, 'workflow' => $this->workflow->id, 'input' => ['order' => 1]])
            ->assertHasErrors(['This token doesn\'t have the runs:write scope.']);
    }

    public function test_clients_can_register_ten_times_a_minute(): void
    {
        $register = fn () => $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']]);

        foreach (range(1, 10) as $attempt) {
            $register()->assertSuccessful();
        }

        $register()->assertTooManyRequests();
    }

    public function test_the_settings_page_leaves_out_the_mcp_url_when_the_server_is_off(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('api-tokens.edit'))->assertSee('data-test="mcp-server-url"', false);

        config(['services.mcp_server_enabled' => false]);
        $this->actingAs($user)->get(route('api-tokens.edit'))->assertOk()->assertDontSee('data-test="mcp-server-url"', false);
    }
}
