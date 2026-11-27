<?php

namespace Tests\Feature\Nodes;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Models\Credential;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class HttpRequestTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            /** @var array<string, list<string>> */
            public array $answers = [
                'api.example.com' => ['93.184.215.14'],
                'redirector.example.com' => ['93.184.215.15'],
                'gitlab.corp.internal' => ['10.20.0.5'],
                'rebind.example.com' => ['93.184.215.16', '127.0.0.1'],
            ];

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? parent::resolve($host);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, int>|null  $retry
     */
    private function httpWorkflow(array $config, ?Workspace $workspace = null, array $edges = ['start->call'], array $extraNodes = [], ?array $retry = null): Workflow
    {
        return $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', $config, $retry], ...$extraNodes],
            $edges,
        ), $workspace);
    }

    public function test_sends_templated_request_with_api_token_auth_and_decodes_json(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['id' => 42, 'name' => 'Widget'], 200, ['Content-Type' => 'application/json'])]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('shop'), 'description' => 'shop', 'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'tok_123456']]);
        $workflow = $this->httpWorkflow([
            'method' => 'POST',
            'url' => 'https://api.example.com/orders/{{ trigger.values.id }}',
            'credential' => $this->settingId('shop'),
            'query' => ['expand' => 'items'],
            'headers' => ['X-Request' => 'run-{{ execution.id }}'],
            'body_type' => 'json',
            'body' => '{"note": "{{ trigger.values.note }}"}',
        ], $workspace);

        $execution = $this->runWorkflow($workflow, ['values' => ['id' => 7, 'note' => 'rush']]);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.example.com/orders/7?expand=items'
            && $request->header('Authorization') === ['Bearer tok_123456']
            && $request->header('X-Request') === ["run-{$execution->id}"]
            && $request->data() === ['note' => 'rush']);

        $output = $this->stepFor($execution, 'call')->output;
        $this->assertSame(200, $output['status']);
        $this->assertTrue($output['ok']);
        $this->assertSame(['id' => 42, 'name' => 'Widget'], $output['body']);
    }

    public function test_applies_http_basic_authentication(): void
    {
        Http::fake(['api.example.com/*' => Http::response('ok')]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->httpBasic()->create(['id' => $this->settingId('intranet'), 'description' => 'intranet', 'config' => ['username' => 'svc', 'password' => 'pw-secret']]);

        $this->runWorkflow($this->httpWorkflow(['url' => 'https://api.example.com/ping', 'credential' => $this->settingId('intranet')], $workspace));

        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Basic '.base64_encode('svc:pw-secret')]);
    }

    public function test_client_errors_fail_without_retrying_and_the_error_output_receives_the_response(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['error' => 'missing'], 404, ['Content-Type' => 'application/json'])]);
        $workflow = $this->httpWorkflow(
            ['url' => 'https://api.example.com/x'],
            edges: ['start->call', 'call:error->handle'],
            extraNodes: ['handle' => ['core.compose', ['template' => '{{ error.message }}']]],
            retry: ['max_retries' => 2, 'backoff_seconds' => 0],
        );

        $execution = $this->runWorkflow($workflow);

        $call = $this->stepFor($execution, 'call');
        $this->assertSame(1, $execution->steps()->where('node_id', 'call')->count());
        $this->assertSame(404, $call->output['status']);
        $this->assertSame('HTTP 404 Not Found', $this->stepFor($execution, 'handle')->output['value']);
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
    }

    public function test_server_errors_are_retried(): void
    {
        Http::fake(['api.example.com/*' => Http::sequence()->push('down', 503)->push('up', 200)]);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['url' => 'https://api.example.com/x'], ['max_retries' => 2, 'backoff_seconds' => 0]]],
            ['start->call'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call', 1)->status);
        $this->assertSame(StepStatus::Complete, $this->stepFor($execution, 'call', 2)->status);
    }

    #[TestWith(['http://127.0.0.1/admin', '127.0.0.1'])]
    #[TestWith(['http://localhost:8080/', '127.0.0.1'])]
    #[TestWith(['http://169.254.169.254/latest/meta-data/', '169.254.169.254'])]
    #[TestWith(['http://[::1]/', '::1'])]
    #[TestWith(['http://[::ffff:127.0.0.1]/', '::ffff:127.0.0.1'])]
    #[TestWith(['http://gitlab.corp.internal/api', '10.20.0.5'])]
    #[TestWith(['http://rebind.example.com/', '127.0.0.1'])]
    #[TestWith(['http://100.64.1.1/', '100.64.1.1'])]
    public function test_blocks_internal_addresses_that_are_not_allowlisted(string $url, string $ip): void
    {
        Http::fake();

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => $url]));

        Http::assertNothingSent();
        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertStringContainsString("internal address {$ip}", $execution->error['message']);
    }

    public function test_allows_internal_hosts_on_the_workspace_allowlist(): void
    {
        Http::fake(['gitlab.corp.internal/*' => Http::response('ok')]);
        $workspace = Workspace::factory()->create(['internal_allowlist' => ['*.corp.internal']]);

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => 'http://gitlab.corp.internal/api'], $workspace));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
    }

    public function test_checks_every_redirect_hop(): void
    {
        Http::fake([
            'redirector.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('should not be reached'),
        ]);

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => 'https://redirector.example.com/go']));

        Http::assertSentCount(1);
        $this->assertStringContainsString('internal address 169.254.169.254', $execution->error['message']);
    }

    public function test_follows_safe_redirects(): void
    {
        Http::fake([
            'redirector.example.com/*' => Http::response('', 301, ['Location' => 'https://api.example.com/final']),
            'api.example.com/*' => Http::response('arrived'),
        ]);

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => 'https://redirector.example.com/go']));

        $this->assertSame('arrived', $this->stepFor($execution, 'call')->output['body']);
    }

    /**
     * A workflow calling https://redirector.example.com/go with a token, a custom header, and a query parameter.
     */
    private function authenticatedRedirect(): Workflow
    {
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('shop'), 'description' => 'shop', 'config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'tok_123456']]);

        return $this->httpWorkflow([
            'url' => 'https://redirector.example.com/go',
            'credential' => $this->settingId('shop'),
            'headers' => ['X-Api-Key' => 'key-abc'],
            'query' => ['api_key' => 'q-secret'],
        ], $workspace);
    }

    public function test_a_redirect_to_another_host_gets_no_authentication_headers_or_query(): void
    {
        Http::fake([
            'redirector.example.com/*' => Http::response('', 302, ['Location' => 'https://api.example.com/final']),
            'api.example.com/*' => Http::response('arrived'),
        ]);

        $execution = $this->runWorkflow($this->authenticatedRedirect());

        $this->assertSame('arrived', $this->stepFor($execution, 'call')->output['body']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://redirector.example.com/go?api_key=q-secret'
            && $request->header('Authorization') === ['Bearer tok_123456'] && $request->header('X-Api-Key') === ['key-abc']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/final'
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Api-Key'));
    }

    public function test_a_redirect_from_https_to_http_on_the_same_host_gets_no_authentication_or_headers(): void
    {
        Http::fake([
            'https://redirector.example.com/*' => Http::response('', 301, ['Location' => 'http://redirector.example.com/plain']),
            'http://redirector.example.com/*' => Http::response('arrived'),
        ]);

        $this->runWorkflow($this->authenticatedRedirect());

        Http::assertSent(fn (Request $request) => $request->url() === 'http://redirector.example.com/plain'
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Api-Key'));
    }

    public function test_a_redirect_on_the_same_host_over_https_keeps_the_authentication_and_headers(): void
    {
        Http::fake([
            'redirector.example.com/go*' => Http::response('', 302, ['Location' => '/v2/go?api_key=q-secret']),
            'redirector.example.com/v2/*' => Http::response('arrived'),
        ]);

        $this->runWorkflow($this->authenticatedRedirect());

        Http::assertSent(fn (Request $request) => $request->url() === 'https://redirector.example.com/v2/go?api_key=q-secret'
            && $request->header('Authorization') === ['Bearer tok_123456'] && $request->header('X-Api-Key') === ['key-abc']);
    }

    public function test_a_plain_http_url_keeps_its_authentication_across_same_host_redirects(): void
    {
        Http::fake([
            'http://redirector.example.com/go*' => Http::response('', 302, ['Location' => 'http://redirector.example.com/next']),
            'http://redirector.example.com/next' => Http::response('arrived'),
        ]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->httpBasic()->create(['id' => $this->settingId('intranet'), 'description' => 'intranet', 'config' => ['username' => 'svc', 'password' => 'pw-secret']]);

        $this->runWorkflow($this->httpWorkflow(['url' => 'http://redirector.example.com/go', 'credential' => $this->settingId('intranet')], $workspace));

        Http::assertSent(fn (Request $request) => $request->url() === 'http://redirector.example.com/next' && $request->hasHeader('Authorization'));
    }

    #[TestWith(['file:///etc/passwd'])]
    #[TestWith(['gopher://api.example.com/'])]
    #[TestWith(['not a url'])]
    public function test_refuses_urls_that_are_not_http(string $url): void
    {
        Http::fake();

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => $url]));

        Http::assertNothingSent();
        $this->assertSame('Only http and https URLs can be called.', $execution->error['message']);
    }

    public function test_authentication_values_never_reach_the_step_log(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['echo' => 'tok_abcdef123'], 200, ['Content-Type' => 'application/json'])]);
        $workspace = Workspace::factory()->create();
        Credential::factory()->for($workspace)->create(['id' => $this->settingId('api'), 'description' => 'api', 'config' => ['header' => 'X-Key', 'prefix' => '', 'token' => 'tok_abcdef123']]);

        $execution = $this->runWorkflow($this->httpWorkflow(['url' => 'https://api.example.com/x', 'credential' => $this->settingId('api')], $workspace));

        $this->assertStringNotContainsString('tok_abcdef123', json_encode($execution->steps()->get(['input', 'output'])->toArray()));
    }

    public function test_allow_insecure_skips_certificate_checks_only_when_turned_on(): void
    {
        $verified = [];
        Http::fake(function ($request, array $options) use (&$verified) {
            $verified[] = $options['verify'];

            return Http::response('ok');
        });

        $this->runWorkflow($this->httpWorkflow(['method' => 'GET', 'url' => 'https://api.example.com/backup']));
        $this->runWorkflow($this->httpWorkflow(['method' => 'GET', 'url' => 'https://api.example.com/backup', 'allow_insecure' => true]));

        $this->assertSame([true, false], $verified);
    }
}
