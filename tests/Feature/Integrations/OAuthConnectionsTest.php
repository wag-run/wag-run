<?php

namespace Tests\Feature\Integrations;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\Credential;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
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

    private function issueWorkflow(): Workflow
    {
        return Workflow::factory()->for($this->workspace)->create(['draft_graph' => $this->graph(
            ['start' => 'core.manual_trigger', 'issue' => ['github.create_issue', ['title' => 'Hi']]],
            ['start->issue'],
        )]);
    }

    private function editor(Workflow $workflow, ?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test('pages::workflows.edit', ['workspace' => $this->workspace, 'workflow' => $workflow]);
    }

    /**
     * @return array<string, string>
     */
    private function authorizeQuery(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['url' => strtok($location, '?'), ...$query];
    }

    public function test_connecting_github_from_a_node_creates_the_setting_and_fills_it_in(): void
    {
        $workflow = $this->issueWorkflow();

        $location = $this->editor($workflow)
            ->call('selectNode', 'issue')
            ->assertSee('data-test="connect-account"', false)
            ->assertSee('Connect GitHub')
            ->call('connectAccount', 'credential')
            ->effects['redirect'];

        $query = $this->authorizeQuery($location);
        $this->assertSame('https://github.com/login/oauth/authorize', $query['url']);
        $this->assertSame('gh-client', $query['client_id']);
        $this->assertSame('repo admin:repo_hook', $query['scope']);
        $this->assertSame(route('integrations.oauth.callback', 'github'), $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(0, Credential::count(), 'nothing is saved until GitHub approves');

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_new', 'scope' => 'repo,admin:repo_hook', 'token_type' => 'bearer']),
            'api.github.com/user' => Http::response(['login' => 'octocat']),
        ]);

        $this->actingAs($this->admin)->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $query['state'], 'code' => 'abc']))
            ->assertRedirect(route('workspaces.workflows.edit', [$this->workspace, $workflow]))
            ->assertSessionHas('oauth_status', 'GitHub (octocat) is connected.')
            ->assertSessionHas('editor_select_node', 'issue');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['code'] === 'abc'
            && $request['client_secret'] === 'gh-secret'
            && strlen($request['code_verifier']) === 64);
        $credential = Credential::sole();
        $this->assertSame(['GitHub (octocat)', 'github'], [$credential->description, $credential->type]);
        $this->assertSame('gho_new', $credential->config['access_token']);
        $this->assertSame(['gho_new'], $credential->secretValues());
        $this->assertSame($credential->id, $workflow->refresh()->draft()->node('issue')->config['credential']);
        $this->assertSame(['credential.created', 'credential.connected'], AuditLog::query()->orderBy('id')->pluck('action')->all());

        $this->withSession(['editor_select_node' => 'issue', 'oauth_status' => 'GitHub (octocat) is connected.']);
        $this->editor($workflow)
            ->assertSet('selected', 'issue')
            ->assertSet('form.credential', $credential->id)
            ->assertSee('Connect another GitHub account')
            ->assertSee('data-test="connection-result"', false);
    }

    public function test_a_second_account_with_the_same_name_gets_its_own_description(): void
    {
        Credential::factory()->for($this->workspace)->oauth()->create(['description' => 'GitHub (octocat)']);
        $location = $this->editor($this->issueWorkflow())->call('selectNode', 'issue')->call('connectAccount', 'credential')->effects['redirect'];
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_two']),
            'api.github.com/user' => Http::response(['login' => 'octocat']),
        ]);

        $this->actingAs($this->admin)->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $this->authorizeQuery($location)['state'], 'code' => 'abc']));

        $this->assertSame(['GitHub (octocat)', 'GitHub (octocat) (2)'], Credential::query()->orderBy('id')->pluck('description')->all());
    }

    public function test_only_people_who_manage_authentication_can_connect_from_a_node(): void
    {
        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();

        $this->editor($this->issueWorkflow(), $editor)
            ->call('selectNode', 'issue')
            ->assertDontSee('data-test="connect-account"', false)
            ->assertSee('Ask a workspace admin to connect a GitHub account.')
            ->call('connectAccount', 'credential')
            ->assertForbidden();
    }

    public function test_the_node_says_what_to_set_when_the_app_is_missing_from_the_environment(): void
    {
        config(['services.github' => ['client_id' => null, 'client_secret' => null]]);

        $this->editor($this->issueWorkflow())
            ->call('selectNode', 'issue')
            ->assertDontSee('data-test="connect-account"', false)
            ->assertSee('Connecting GitHub isn\'t set up on this install yet. Set GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET in the environment first.')
            ->call('connectAccount', 'credential')
            ->assertNotFound();
    }

    public function test_the_add_authentication_form_connects_github_instead_of_asking_for_details(): void
    {
        $page = Livewire::actingAs($this->admin)->test('pages::workspaces.credentials', ['workspace' => $this->workspace])
            ->call('create')
            ->set('type', 'github')
            ->assertSee('data-test="connect-account"', false)
            ->assertDontSee('placeholder="github_api"', false);

        $location = $page->call('connect')->effects['redirect'];
        $this->assertSame('https://github.com/login/oauth/authorize', $this->authorizeQuery($location)['url']);
        $page->call('save')->assertHasErrors(['type' => 'in']);
        $this->assertSame(0, Credential::count());

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_page']),
            'api.github.com/user' => Http::response(['login' => 'release-bot']),
        ]);

        $this->actingAs($this->admin)->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $this->authorizeQuery($location)['state'], 'code' => 'abc']))
            ->assertRedirect(route('workspaces.credentials', $this->workspace))
            ->assertSessionHas('oauth_status', 'GitHub (release-bot) is connected.')
            ->assertSessionMissing('editor_select_node');
        $this->assertSame('GitHub (release-bot)', Credential::sole()->description);
    }

    public function test_without_an_install_app_a_github_setting_brings_its_own_client(): void
    {
        config(['services.github' => ['client_id' => null, 'client_secret' => null]]);

        $page = Livewire::actingAs($this->admin)->test('pages::workspaces.credentials', ['workspace' => $this->workspace])
            ->call('create')
            ->set('type', 'github')
            ->assertDontSee('data-test="connect-account"', false)
            ->assertSee(route('integrations.oauth.callback', 'github'))
            ->set('description', 'Release bot')
            ->call('save')
            ->assertHasErrors(['config.client_id' => 'required', 'config.client_secret' => 'required'])
            ->set('config.client_id', 'Iv1.own')
            ->set('config.client_secret', 'own-secret')
            ->call('save');

        $credential = Credential::sole();
        $page->assertRedirect(route('workspaces.credentials.connect', [$this->workspace, $credential]));
        $query = $this->connect($credential);
        $this->assertSame('Iv1.own', $query['client_id']);

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_own']),
            'api.github.com/user' => Http::response(['login' => 'octocat']),
        ]);
        $this->get(route('integrations.oauth.callback', ['type' => 'github', 'state' => $query['state'], 'code' => 'abc']))
            ->assertSessionHas('oauth_status');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://github.com/login/oauth/access_token'
            && $request['client_id'] === 'Iv1.own' && $request['client_secret'] === 'own-secret');
        $this->assertSame('ghu_own', $credential->refresh()->config['access_token']);
    }

    public function test_a_github_settings_own_client_wins_over_the_install_app(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['description' => 'Own app', 'type' => 'github', 'config' => ['client_id' => 'Iv1.own', 'client_secret' => 'own-secret']]);

        $this->assertSame('Iv1.own', $this->connect($credential)['client_id']);
    }

    public function test_a_github_node_offers_tokens_and_apps_and_still_connects_accounts(): void
    {
        Credential::factory()->for($this->workspace)->create(['description' => 'Bot token', 'type' => 'github_token', 'config' => ['token' => 'github_pat_abc']]);
        Credential::factory()->for($this->workspace)->create(['description' => 'Deploy app', 'type' => 'github_app', 'config' => ['app_id' => '1', 'installation_id' => '2', 'private_key' => 'k']]);
        Credential::factory()->for($this->workspace)->httpBasic()->create(['description' => 'Intranet']);

        $this->editor($this->issueWorkflow())
            ->call('selectNode', 'issue')
            ->assertSee('Bot token')
            ->assertSee('Deploy app')
            ->assertDontSee('Intranet')
            ->assertSee('Connect another GitHub account');
    }

    public function test_a_callback_from_another_attempt_or_a_declined_consent_is_refused(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('github'), 'description' => 'github', 'type' => 'github', 'config' => []]);

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

    public function test_reconnecting_needs_the_app_set_up_in_the_environment(): void
    {
        config(['services.github' => ['client_id' => null, 'client_secret' => null]]);
        $credential = Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('github'), 'description' => 'github', 'type' => 'github', 'config' => []]);

        $this->actingAs($this->admin)->get(route('workspaces.credentials.connect', [$this->workspace, $credential]))
            ->assertRedirect(route('workspaces.credentials', $this->workspace))
            ->assertSessionHas('oauth_error', 'The GitHub app isn\'t set up on this install yet. An administrator needs to set GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET in the environment.');
    }

    public function test_editors_cannot_connect_settings(): void
    {
        $editor = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $credential = Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('github'), 'description' => 'github', 'type' => 'github', 'config' => []]);

        $this->actingAs($editor)->get(route('workspaces.credentials.connect', [$this->workspace, $credential]))->assertForbidden();
    }

    public function test_editing_a_connected_setting_keeps_its_connection(): void
    {
        $credential = Credential::factory()->for($this->workspace)->oauth()->create(['id' => $this->settingId('github'), 'description' => 'github']);

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
        Credential::factory()->for($this->workspace)->oauth('google', ['access_token' => 'ya29.old', 'refresh_token' => '1//refresh', 'expires_at' => now()->subMinute()->getTimestamp()])->create(['id' => $this->settingId('sheets'), 'description' => 'sheets']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'expires_in' => 3599]),
            'sheets.googleapis.com/*' => Http::response(['ok' => true]),
        ]);

        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['method' => 'GET', 'url' => 'https://sheets.googleapis.com/v4/spreadsheets/abc', 'credential' => $this->settingId('sheets')]]],
            ['start->call'],
        ), $this->workspace));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === '1//refresh');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sheets.googleapis.com') && $request->header('Authorization') === ['Bearer ya29.new']);
        $config = Credential::firstWhere('description', 'sheets')->config;
        $this->assertSame('1//refresh', $config['refresh_token']);
        $this->assertGreaterThan(now()->getTimestamp() + 3000, $config['expires_at']);
    }

    public function test_a_generic_provider_cannot_send_its_token_request_to_an_internal_address(): void
    {
        $credential = Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('internal'), 'description' => 'internal', 'type' => 'oauth2', 'config' => [
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
