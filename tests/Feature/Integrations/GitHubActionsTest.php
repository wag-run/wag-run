<?php

namespace Tests\Feature\Integrations;

use App\Enums\StepStatus;
use App\Models\Credential;
use App\Models\ExecutionStep;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class GitHubActionsTest extends TestCase
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
     * @param  array<string, mixed>  $input
     */
    private function runAction(string $type, array $config, array $input = []): ExecutionStep
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => [$type, ['credential' => $this->settingId('github'), 'repository' => '{{ trigger.values.repo }}', ...$config], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $this->workspace), ['values' => ['repo' => 'https://github.com/acme/app', ...$input]]);

        return $this->stepFor($execution, 'act');
    }

    public function test_creates_an_issue_with_labels_and_assignees(): void
    {
        Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['id' => 1, 'number' => 42, 'html_url' => 'https://github.com/acme/app/issues/42'], 201)]);

        $step = $this->runAction('github.create_issue', ['title' => 'Deploy of {{ trigger.values.tag }} failed', 'labels' => 'bug, ops', 'assignees' => ''], ['tag' => 'v1']);

        $this->assertSame(['number' => 42, 'url' => 'https://github.com/acme/app/issues/42', 'id' => 1], $step->output);
        Http::assertSent(fn (Request $request) => $request->data() === ['title' => 'Deploy of v1 failed', 'labels' => ['bug', 'ops']]
            && $request->header('Authorization') === ['Bearer gho_access']
            && $request->header('X-GitHub-Api-Version') === ['2022-11-28']);
    }

    public function test_acts_with_a_personal_access_token(): void
    {
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('pat'), 'description' => 'pat', 'type' => 'github_token', 'config' => ['token' => 'github_pat_abc']]);
        Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['id' => 1, 'number' => 42, 'html_url' => 'https://github.com/acme/app/issues/42'], 201)]);

        $step = $this->runAction('github.create_issue', ['credential' => $this->settingId('pat'), 'title' => 'x']);

        $this->assertSame(42, $step->output['number']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/app/issues'
            && $request->header('Authorization') === ['Bearer github_pat_abc']);
    }

    public function test_acts_as_a_github_app_with_an_installation_token_it_reuses_until_shortly_before_it_expires(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('app'), 'description' => 'app', 'type' => 'github_app', 'config' => [
            'app_id' => '123', 'installation_id' => '456', 'private_key' => $privateKey,
        ]]);
        $this->freezeTime();
        Http::fake([
            'api.github.com/app/installations/456/access_tokens' => Http::response(['token' => 'ghs_install', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/repos/acme/app/issues' => Http::response(['id' => 1, 'number' => 42, 'html_url' => 'https://github.com/acme/app/issues/42'], 201),
        ]);
        $tokenRequests = fn () => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/app/installations/456/access_tokens'));

        $this->runAction('github.create_issue', ['credential' => $this->settingId('app'), 'title' => 'x']);
        $this->runAction('github.create_issue', ['credential' => $this->settingId('app'), 'title' => 'y']);

        $this->assertCount(1, $tokenRequests());
        [$header, $payload, $signature] = explode('.', substr($tokenRequests()[0][0]->header('Authorization')[0], strlen('Bearer ')));
        $decode = fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'));
        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), (string) openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256));
        $claims = json_decode($decode($payload), true);
        $this->assertSame(['123', now()->getTimestamp() - 60, now()->getTimestamp() + 540], [$claims['iss'], $claims['iat'], $claims['exp']]);
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/issues') && $request->header('Authorization') === ['Bearer ghs_install']));

        $this->travel(56)->minutes();
        $this->runAction('github.create_issue', ['credential' => $this->settingId('app'), 'title' => 'z']);

        $this->assertCount(2, $tokenRequests());
    }

    public function test_a_github_app_with_an_unreadable_key_or_a_refused_token_fails_the_step(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('broken'), 'description' => 'Broken app', 'type' => 'github_app', 'config' => [
            'app_id' => '123', 'installation_id' => '456', 'private_key' => 'not a key',
        ]]);
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('refused'), 'description' => 'Refused app', 'type' => 'github_app', 'config' => [
            'app_id' => '123', 'installation_id' => '789', 'private_key' => $privateKey,
        ]]);
        Http::fake(['api.github.com/app/installations/789/access_tokens' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->assertSame(
            'The private key of the GitHub App "Broken app" can\'t be read. Paste the whole .pem file into its credential.',
            $this->runAction('github.create_issue', ['credential' => $this->settingId('broken'), 'title' => 'x'])->error['message'],
        );
        $this->assertSame(
            'GitHub didn\'t give the GitHub App "Refused app" a token. GitHub answered 401: Bad credentials',
            $this->runAction('github.create_issue', ['credential' => $this->settingId('refused'), 'title' => 'x'])->error['message'],
        );
    }

    public function test_comments_creates_releases_and_runs_actions_workflows(): void
    {
        Http::fake([
            'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 3, 'html_url' => 'https://github.com/acme/app/issues/7#c3'], 201),
            'api.github.com/repos/acme/app/releases' => Http::response(['id' => 9, 'tag_name' => 'v2', 'html_url' => 'https://github.com/acme/app/releases/v2'], 201),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(null, 204),
        ]);

        $this->assertSame(3, $this->runAction('github.add_comment', ['number' => '#7', 'body' => 'Shipped'])->output['id']);
        $this->assertSame('v2', $this->runAction('github.create_release', ['tag' => 'v2', 'generate_notes' => true])->output['tag']);
        $this->assertSame(['workflow' => 'deploy.yml', 'ref' => 'main'], $this->runAction('github.run_actions_workflow', ['workflow' => 'deploy.yml', 'ref' => 'main', 'inputs' => ['env' => 'production']])->output);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/releases') && $request->data() === ['tag_name' => 'v2', 'generate_release_notes' => true, 'draft' => false, 'prerelease' => false]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/dispatches') && $request->data() === ['ref' => 'main', 'inputs' => ['env' => 'production']]);
    }

    public function test_github_errors_fail_the_step_and_only_server_errors_are_retried(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['message' => 'Validation Failed'], 422)
            ->push(['message' => 'Server Error'], 502)
            ->push(['id' => 1, 'number' => 2, 'html_url' => 'u'], 201)]);

        $invalid = $this->runAction('github.create_issue', ['title' => 'x']);
        $this->assertSame(StepStatus::Failed, $invalid->status);
        $this->assertSame('GitHub answered 422: Validation Failed', $invalid->error['message']);
        $this->assertSame(1, $invalid->execution->steps()->where('node_id', 'act')->count(), 'not retried');

        $retried = $this->runAction('github.create_issue', ['title' => 'x']);
        $this->assertSame(StepStatus::Complete, $this->stepFor($retried->execution, 'act', 2)->status);
    }

    public function test_a_missing_connection_or_bad_repository_fails_without_calling_github(): void
    {
        Http::fake();

        $this->assertSame('The GitHub connection chosen on this node no longer exists. Choose or connect another in the node\'s settings.', $this->runAction('github.create_issue', ['credential' => $this->settingId('nope'), 'title' => 'x'])->error['message']);
        $this->assertStringContainsString('isn\'t a repository', $this->runAction('github.create_issue', ['title' => 'x'], ['repo' => 'acme'])->error['message']);
        Http::assertNothingSent();
    }
}
