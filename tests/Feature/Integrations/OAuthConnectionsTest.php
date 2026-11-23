<?php

namespace Tests\Feature\Integrations;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Credential;
use App\Models\User;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class OAuthConnectionsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.github' => ['client_id' => 'gh-client', 'client_secret' => 'gh-secret']]);
        $this->workspace = Workspace::factory()->create();
        $this->admin = User::factory()->memberOf($this->workspace, WorkspaceRole::Admin)->create();
    }

    /**
     * @return array<string, string>
     */
    private function connect(Credential $credential): array
    {
        $location = $this->actingAs($this->admin)
            ->get(route('workspaces.credentials.connect', [$this->workspace, $credential]))
            ->assertRedirect()
            ->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['url' => strtok($location, '?'), ...$query];
    }

    public function test_saving_a_github_setting_connects_it_through_oauth(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::workspaces.credentials', ['workspace' => $this->workspace])
            ->set('type', 'github')
            ->set('key', 'github')
            ->call('save')
            ->assertRedirect(route('workspaces.credentials.connect', [$this->workspace, Credential::sole()]));

        $query = $this->connect(Credential::sole());

        $this->assertSame('https://github.com/login/oauth/authorize', $query['url']);
        $this->assertSame('gh-client', $query['client_id']);
        $this->assertSame('repo admin:repo_hook', $query['scope']);
        $this->assertSame(route('integrations.oauth.callback', 'github'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_new', 'scope' => 'repo,admin:repo_hook', 'token_type' => 'bearer']),
            'api.github.com/user' => Http::response(['login' => 'octocat']),
        ]);

        $this->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $query['state'], 'code' => 'abc']))
            ->assertRedirect(route('workspaces.credentials', $this->workspace))
            ->assertSessionHas('oauth_status', 'github is connected as octocat.');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['code'] === 'abc'
            && $request['client_secret'] === 'gh-secret'
            && strlen($request['code_verifier']) === 64);
        $credential = Credential::sole();
        $this->assertSame('gho_new', $credential->config['access_token']);
        $this->assertSame('octocat', $credential->config['account']);
        $this->assertSame(['gho_new'], $credential->secretValues());
        $this->assertSame(['credential.created', 'credential.connected'], AuditLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_a_callback_from_another_attempt_or_a_declined_consent_is_refused(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['key' => 'github', 'type' => 'github', 'config' => []]);

        $this->actingAs($this->admin)->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => 'forged', 'code' => 'abc']))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('oauth_error');

        $query = $this->connect($credential);
        $this->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $query['state'], 'error' => 'access_denied']))
            ->assertRedirect(route('workspaces.credentials', $this->workspace))
            ->assertSessionHas('oauth_error', 'The provider didn\'t allow the connection: access_denied');

        $this->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $query['state'], 'code' => 'abc']))
            ->assertSessionHas('oauth_error', 'This connection attempt expired or was started by someone else. Try connecting again.');
        $this->assertSame([], $credential->refresh()->config);
    }

    public function test_an_app_missing_from_the_environment_is_explained(): void
    {
        config(['services.github' => ['client_id' => null, 'client_secret' => null]]);
        $credential = Credential::factory()->for($this->workspace)->create(['key' => 'github', 'type' => 'github', 'config' => []]);

        $this->actingAs($this->admin)->get(route('workspaces.credentials.connect', [$this->workspace, $credential]))
            ->assertRedirect(route('workspaces.credentials', $this->workspace))
            ->assertSessionHas('oauth_error', 'The GitHub app isn\'t set up on this install yet. An administrator needs to set GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET in the environment.');

        Livewire::actingAs($this->admin)
            ->test('pages::workspaces.credentials', ['workspace' => $this->workspace])
            ->set('type', 'github')
            ->assertSee('data-test="oauth-not-configured"', false);
    }

    public function test_editors_cannot_connect_settings(): void
    {
        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $credential = Credential::factory()->for($this->workspace)->create(['key' => 'github', 'type' => 'github', 'config' => []]);

        $this->actingAs($editor)->get(route('workspaces.credentials.connect', [$this->workspace, $credential]))->assertForbidden();
    }

    public function test_editing_a_connected_setting_keeps_its_connection(): void
    {
        $credential = Credential::factory()->for($this->workspace)->oauth()->create(['key' => 'github']);

        Livewire::actingAs($this->admin)
            ->test('pages::workspaces.credentials', ['workspace' => $this->workspace])
            ->call('edit', $credential->id)
            ->set('description', 'Release bot')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('gho_access', $credential->refresh()->config['access_token']);
        $this->assertSame('Release bot', $credential->description);
    }

    public function test_http_calls_refresh_an_expired_token_and_keep_the_refresh_token(): void
    {
        config(['services.google' => ['client_id' => 'g-client', 'client_secret' => 'g-secret']]);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Credential::factory()->for($this->workspace)->oauth('google', ['access_token' => 'ya29.old', 'refresh_token' => '1//refresh', 'expires_at' => now()->subMinute()->getTimestamp()])->create(['key' => 'sheets']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'expires_in' => 3599]),
            'sheets.googleapis.com/*' => Http::response(['ok' => true]),
        ]);

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['method' => 'GET', 'url' => 'https://sheets.googleapis.com/v4/spreadsheets/abc', 'credential' => 'sheets']]],
            ['start->call'],
        ), $this->workspace));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === '1//refresh');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sheets.googleapis.com') && $request->header('Authorization') === ['Bearer ya29.new']);
        $config = Credential::firstWhere('key', 'sheets')->config;
        $this->assertSame('1//refresh', $config['refresh_token']);
        $this->assertGreaterThan(now()->getTimestamp() + 3000, $config['expires_at']);
    }

    public function test_a_generic_provider_cannot_send_its_token_request_to_an_internal_address(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['key' => 'internal', 'type' => 'oauth2', 'config' => [
            'authorize_url' => 'https://auth.example.com/authorize', 'token_url' => 'http://127.0.0.1/token', 'client_id' => 'id', 'client_secret' => 'secret', 'scope' => 'read write',
        ]]);

        $query = $this->connect($credential);
        $this->assertSame('https://auth.example.com/authorize', $query['url']);
        $this->assertSame('read write', $query['scope']);

        $this->get(route('integrations.oauth.callback', ['type' => 'oauth2', 'state' => $query['state'], 'code' => 'abc']))
            ->assertSessionHas('oauth_error', fn (string $error) => str_contains($error, 'internal'));
        Http::assertNothingSent();
    }
}
