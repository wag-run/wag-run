<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Token;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys');
        }
    }

    public function test_a_person_creates_a_token_that_works_with_its_scopes_and_revokes_it(): void
    {
        $user = User::factory()->orgAdmin()->create();

        $page = Livewire::actingAs($user)->test('pages::settings.api-tokens')
            ->set('name', 'Deploy script')
            ->set('scopes', ['workflows:read', 'mcp:use'])
            ->call('create')
            ->assertHasErrors(['scopes.1' => 'in'])
            ->set('scopes', ['workflows:read'])
            ->call('create')
            ->assertHasNoErrors();

        $plain = $page->get('plainToken');
        $token = Token::sole();
        $this->assertSame(['workflows:read'], $token->scopes);
        $this->assertSame('api_token.created', AuditLog::sole()->action);
        $page->assertSee('Deploy script');

        $this->withToken($plain)->getJson('/api/v1/workspaces')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/workspaces/any/runs')->assertNotFound();

        $page->call('revoke', $token->id);
        $this->assertTrue($token->refresh()->revoked);
        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/v1/workspaces')->assertUnauthorized();
    }

    public function test_people_see_and_disconnect_apps_they_connected_through_oauth(): void
    {
        $user = User::factory()->orgAdmin()->create();
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false);
        $token = $user->tokens()->forceCreate(['id' => str_repeat('a', 80), 'user_id' => $user->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addDay()]);

        Livewire::actingAs($user)->test('pages::settings.api-tokens')
            ->assertSee('data-test="connected-app"', false)
            ->call('disconnect', $client->id)
            ->assertDontSee('data-test="connected-app"', false);

        $this->assertTrue($token->refresh()->revoked);
    }

    public function test_the_consent_screen_names_the_app_and_what_it_can_do(): void
    {
        $user = User::factory()->create();
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/callback'], confidential: false);

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://claude.ai/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 64), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))
            ->assertOk()
            ->assertSee('Connect Claude to wag.run?')
            ->assertSee(__('api.scopes.mcp_use'))
            ->assertSee('data-test="authorize-app"', false);
    }
}
