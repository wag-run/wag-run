<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

/**
 * A person's access to the API and the MCP server: personal access tokens
 * they create themselves, and apps such as Claude that they connected to
 * the MCP server through OAuth.
 *
 * Audit entries name the person; a token's id is longer than the log keeps.
 */
class ApiTokens
{
    public const PERSONAL_ACCESS_CLIENT = 'wag.run personal access tokens';

    public function __construct(protected ClientRepository $clients) {}

    /**
     * Create a personal access token and return it as text, the only time
     * it can be read.
     *
     * @param  list<string>  $scopes
     */
    public function create(User $user, string $name, array $scopes): string
    {
        $this->ensurePersonalAccessClient();

        $result = $user->createToken($name, array_values(array_intersect($scopes, array_keys(WorkflowApi::personalTokenScopes()))));

        Audit::record('api_token.created', $user, label: $name, metadata: ['scopes' => $result->getToken()?->scopes]);

        return $result->accessToken;
    }

    /**
     * The person's personal access tokens that still work, newest first.
     *
     * @return Collection<int, Token>
     */
    public function personalTokens(User $user): Collection
    {
        return $this->activeTokens($user)->filter(fn (Token $token) => $this->isPersonal($token))->values();
    }

    /**
     * The apps the person connected through OAuth, such as an MCP client,
     * one token per app: the newest.
     *
     * @return Collection<int, Token>
     */
    public function connectedApps(User $user): Collection
    {
        return $this->activeTokens($user)
            ->reject(fn (Token $token) => $this->isPersonal($token))
            ->unique('client_id')
            ->values();
    }

    public function revoke(User $user, string $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $token->revoke();

        Audit::record('api_token.revoked', $user, label: $token->name);
    }

    /**
     * Revoke every token an app holds for the person, and the refresh tokens
     * that would get it new ones.
     */
    public function disconnect(User $user, string $clientId): void
    {
        $tokens = $user->tokens()->where('client_id', $clientId)->where('revoked', false)->get();

        foreach ($tokens as $token) {
            $token->revoke();
            $token->refreshToken?->revoke();
        }

        if ($client = Passport::client()->newQuery()->find($clientId)) {
            Audit::record('api_app.disconnected', $user, label: $client->name);
        }
    }

    /**
     * @return Collection<int, Token>
     */
    protected function activeTokens(User $user): Collection
    {
        return $user->tokens()
            ->with('client')
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();
    }

    protected function isPersonal(Token $token): bool
    {
        return $token->client !== null && $token->client->hasGrantType('personal_access');
    }

    protected function ensurePersonalAccessClient(): void
    {
        $exists = Passport::client()->newQuery()->where('revoked', false)->get()
            ->contains(fn (Client $client) => $client->hasGrantType('personal_access'));

        if (! $exists) {
            $this->clients->createPersonalAccessGrantClient(self::PERSONAL_ACCESS_CLIENT, 'users');
        }
    }
}
