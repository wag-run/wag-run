<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\OidcDomain;
use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Support\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OidcSignInTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://id.example.com';

    private OidcProvider $provider;

    /**
     * Claims the fake provider puts in the next ID token.
     *
     * @var array<string, mixed>
     */
    private array $tokenClaims = [];

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->provider = OidcProvider::factory()->for($organization)->create(['name' => 'Acme ID', 'slug' => 'acme-id', 'issuer' => self::ISSUER, 'client_id' => 'wag-run']);

        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token',
                'userinfo_endpoint' => self::ISSUER.'/userinfo',
            ]),
            self::ISSUER.'/token' => fn () => Http::response(['id_token' => $this->idToken($this->tokenClaims), 'access_token' => 'access-123', 'token_type' => 'Bearer']),
            self::ISSUER.'/userinfo' => Http::response(['sub' => 'user-1', 'name' => 'From Userinfo']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function idToken(array $claims): string
    {
        return OidcClient::base64UrlEncode('{"alg":"RS256"}').'.'.OidcClient::base64UrlEncode((string) json_encode($claims)).'.signature';
    }

    /**
     * Start the sign-in, then come back from the provider with the given claims.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, string>  $callbackOverrides
     */
    private function signInWith(array $claims = [], array $callbackOverrides = []): TestResponse
    {
        $redirect = $this->get(route('oidc.redirect', $this->provider));
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $authorization);

        $this->tokenClaims = [
            'iss' => self::ISSUER,
            'aud' => 'wag-run',
            'sub' => 'user-1',
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $authorization['nonce'],
            'email' => 'ada@example.com',
            'email_verified' => true,
            'name' => 'Ada Lovelace',
            ...$claims,
        ];

        return $this->get(route('oidc.callback', [$this->provider, 'code' => 'code-xyz', 'state' => $authorization['state'], ...$callbackOverrides]));
    }

    public function test_the_redirect_sends_the_user_to_the_provider_with_pkce_state_and_nonce(): void
    {
        $response = $this->get(route('oidc.redirect', $this->provider));

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('wag-run', $query['client_id']);
        $this->assertSame(route('oidc.callback', $this->provider), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $session = session('oidc.request');
        $this->assertSame($session['state'], $query['state']);
        $this->assertSame($session['nonce'], $query['nonce']);
        $this->assertSame(OidcClient::base64UrlEncode(hash('sha256', $session['verifier'], true)), $query['code_challenge']);
    }

    public function test_a_user_already_linked_to_the_subject_is_signed_in(): void
    {
        $user = User::factory()->orgMember()->create(['email' => 'someone-else@example.com']);
        OidcIdentity::create(['oidc_provider_id' => $this->provider->id, 'user_id' => $user->id, 'subject' => 'user-1']);

        $this->signInWith()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(
            ['method' => 'oidc', 'provider' => 'Acme ID'],
            AuditLog::query()->where('action', 'auth.signed_in')->sole()->metadata,
        );
        Http::assertSent(fn (Request $request) => $request->url() === self::ISSUER.'/token'
            && $request['code'] === 'code-xyz'
            && $request['code_verifier'] !== null
            && $request->hasHeader('Authorization'));
    }

    public function test_a_verified_email_links_the_sign_in_to_the_existing_account(): void
    {
        $user = User::factory()->orgMember()->create(['email' => 'Ada@example.com']);

        $this->signInWith()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oidc_identities', ['oidc_provider_id' => $this->provider->id, 'subject' => 'user-1', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oidc_linked', 'user_id' => $user->id]);
    }

    public function test_an_unverified_email_is_not_linked_to_an_existing_account_unless_the_provider_is_trusted(): void
    {
        $user = User::factory()->orgMember()->create(['email' => 'ada@example.com']);

        $this->signInWith(['email_verified' => false])->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->provider->update(['trust_email' => true]);

        $this->signInWith(['email_verified' => false])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_people_get_a_member_account_only_when_the_provider_allows_registration(): void
    {
        $this->signInWith()->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'There is no account for this sign-in. Ask an admin to invite you.']);
        $this->assertSame(0, User::count());

        $this->provider->update(['allow_registration' => true]);

        $this->signInWith(['name' => null])->assertRedirect(route('dashboard'));

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertSame('From Userinfo', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->isOrgAdmin());
        $this->assertTrue($user->accessibleWorkspaces()->isEmpty());
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        User::factory()->deactivated()->create(['email' => 'ada@example.com']);

        $this->signInWith()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidTokens(): array
    {
        return [
            'another issuer' => [['iss' => 'https://evil.example.com']],
            'another audience' => [['aud' => 'someone-else']],
            'expired' => [['exp' => time() - 3600]],
            'a replayed nonce' => [['nonce' => 'not-the-one-we-sent']],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_an_id_token_that_fails_a_check_is_refused(array $claims): void
    {
        User::factory()->orgMember()->create(['email' => 'ada@example.com']);

        $this->signInWith($claims)->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_callback_whose_state_does_not_match_is_refused_without_calling_the_provider(): void
    {
        User::factory()->orgMember()->create(['email' => 'ada@example.com']);

        $this->signInWith(callbackOverrides: ['state' => 'forged'])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        Http::assertNotSent(fn (Request $request) => $request->url() === self::ISSUER.'/token');
    }

    public function test_disabled_providers_are_not_offered_and_cannot_be_used(): void
    {
        $this->get(route('login'))->assertSee('Sign in with Acme ID');

        $this->provider->update(['enabled' => false]);

        $this->get(route('login'))->assertDontSee('Sign in with Acme ID');
        $this->get(route('oidc.redirect', $this->provider))->assertNotFound();
    }

    public function test_a_provider_with_email_domains_accepts_addresses_on_them_in_any_case(): void
    {
        OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'example.com']);
        $user = User::factory()->orgMember()->create(['email' => 'ada@example.com']);

        $this->signInWith(['email' => 'Ada@Example.COM'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_provider_with_email_domains_turns_away_other_addresses_even_for_linked_people(): void
    {
        OidcDomain::factory()->for($this->provider, 'provider')->create(['domain' => 'acme.com']);
        $user = User::factory()->orgMember()->create(['email' => 'ada@example.com']);
        OidcIdentity::create(['oidc_provider_id' => $this->provider->id, 'user_id' => $user->id, 'subject' => 'user-1']);

        $this->signInWith()
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => "Acme ID doesn't accept this email address. Ask an admin how you should sign in."]);

        $this->assertGuest();
    }
}
