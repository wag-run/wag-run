<?php

namespace App\Services;

use App\Credentials\OAuthCredentialType;
use App\Engine\Graph\GraphNode;
use App\Enums\WorkspacePermission;
use App\Models\Credential;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Support\AddressAllowlist;
use App\Support\Audit;
use App\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Connects OAuth authentication settings and keeps their tokens fresh.
 *
 * Connecting is the authorization code flow with PKCE. It starts from a
 * saved setting (to connect it or connect it again), or, for a provider
 * whose app is set up in the environment such as GitHub, as a new account:
 * from the Authentication page's Connect button or from a node's account
 * field in the editor. A new account's setting is created only once the
 * provider approves, described by the account, such as "GitHub (octocat)",
 * and, from a node, filled into that node.
 *
 * The session only holds the attempt: who started it, where, and the PKCE
 * verifier. Client secrets never leave the setting or the environment.
 */
class OAuthConnections
{
    protected const SESSION_KEY = 'oauth_connections';

    /**
     * Seconds before an access token expires that it counts as expired.
     */
    protected const EXPIRY_MARGIN = 60;

    public function __construct(
        protected NetworkGuard $guard,
        protected Workflows $workflows,
    ) {}

    /**
     * Start connecting a saved setting: remember the attempt and return the
     * provider's consent page to send the person to.
     */
    public function begin(Credential $credential, User $user): string
    {
        return $this->start($this->typeOf($credential), $credential->workspace, $user, $credential->config, ['credential_id' => $credential->id]);
    }

    /**
     * Start connecting a new account of $type from the workspace's
     * Authentication page. On success the new setting is listed there.
     */
    public function beginForWorkspace(OAuthCredentialType $type, Workspace $workspace, User $user): string
    {
        return $this->start($type, $workspace, $user, [], []);
    }

    /**
     * Start connecting a new account of $type for a node's account field. On
     * success the new setting is filled into that field.
     */
    public function beginForNode(OAuthCredentialType $type, Workflow $workflow, string $nodeId, string $field, User $user): string
    {
        return $this->start($type, $workflow->workspace, $user, [], [
            'workflow_id' => $workflow->id,
            'node_id' => $nodeId,
            'field' => $field,
        ]);
    }

    /**
     * Where to send the person back to after the callback with this state:
     * the node they started from, the workspace's Authentication page, or
     * the dashboard when the attempt is unknown.
     */
    public function returnUrl(string $state): string
    {
        $pending = session()->get(self::SESSION_KEY.'.'.$state);
        $workspace = is_array($pending) ? Workspace::query()->whereKey($pending['workspace_id'])->first() : null;

        if (! $workspace) {
            return route('dashboard');
        }

        $workflow = isset($pending['workflow_id']) ? $workspace->workflows()->whereKey($pending['workflow_id'])->first() : null;

        return $workflow
            ? route('workspaces.workflows.edit', [$workspace, $workflow])
            : route('workspaces.credentials', $workspace);
    }

    /**
     * Finish connecting: check the provider's callback, swap its code for
     * tokens and save them, on the setting the attempt started from or on a
     * new one. An attempt started from a node fills the new setting into
     * it, and flashes the node's id as "editor_select_node".
     *
     * @throws RuntimeException when the attempt is unknown, expired or no longer allowed, the person declined, or the provider refused the code
     */
    public function complete(string $typeKey, Request $request, User $user): Credential
    {
        $pending = session()->pull(self::SESSION_KEY.'.'.$request->query('state', ''));
        $workspace = is_array($pending) ? Workspace::query()->whereKey($pending['workspace_id'])->first() : null;

        if (! $workspace || $pending['user_id'] !== $user->id || $pending['expires_at'] < now()->getTimestamp() || $pending['type'] !== $typeKey) {
            throw new RuntimeException(__('oauth.connection_attempt_expired'));
        }

        if (! $user->hasWorkspacePermission($workspace, WorkspacePermission::ManageCredentials)) {
            throw new RuntimeException(__('oauth.not_allowed_to_connect'));
        }

        if (filled($request->query('error'))) {
            throw new RuntimeException(__('oauth.provider_declined', ['message' => (string) ($request->query('error_description') ?: $request->query('error'))]));
        }

        $credential = isset($pending['credential_id'])
            ? $workspace->credentials()->whereKey($pending['credential_id'])->first()
            : (new Credential(['type' => $typeKey, 'config' => []]))->setRelation('workspace', $workspace);

        if (! $credential || $credential->type !== $typeKey) {
            throw new RuntimeException(__('oauth.connection_attempt_expired'));
        }

        $credential->workspace_id = $workspace->id;
        $type = $this->typeOf($credential);
        $tokens = $this->requestTokens($credential, [
            'grant_type' => 'authorization_code',
            'code' => (string) $request->query('code', ''),
            'redirect_uri' => $type->callbackUrl(),
            'code_verifier' => $pending['verifier'],
        ]);

        try {
            $account = $type->accountName($tokens['access_token']);
        } catch (Throwable) {
            $account = null;
        }

        $created = ! $credential->exists;

        if ($created) {
            $credential->description = $this->freeDescription($workspace, filled($account) ? "{$type->label()} ({$account})" : $type->label());
        }

        $credential->config = [...$credential->config, ...$this->connection($tokens, null), 'account' => $account];
        $credential->save();

        if ($created) {
            Audit::record('credential.created', $credential, $workspace, ['type' => $credential->type]);
        }

        Audit::record('credential.connected', $credential, $workspace, ['type' => $credential->type, 'account' => $account]);

        if (isset($pending['workflow_id'])) {
            $this->fillIntoNode($workspace, $pending, $credential);
        }

        return $credential;
    }

    /**
     * @param  array<string, mixed>  $config  the setting's config, for a provider configured on it
     * @param  array<string, mixed>  $attempt  where the attempt started: credential_id, or workflow_id, node_id and field
     */
    protected function start(OAuthCredentialType $type, Workspace $workspace, User $user, array $config, array $attempt): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        session()->put(self::SESSION_KEY.'.'.$state, [
            ...$attempt,
            'type' => $type->key(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'verifier' => $verifier,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $type->clientId($config),
            'redirect_uri' => $type->callbackUrl(),
            'scope' => implode(' ', $type->scopes($config)),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...$type->authorizeParameters(),
        ]);
        $url = $type->authorizeUrl($config);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * Set the node's account field to the new setting, if the node is still
     * in the draft, and have the editor open it.
     *
     * @param  array<string, mixed>  $pending
     */
    protected function fillIntoNode(Workspace $workspace, array $pending, Credential $credential): void
    {
        $workflow = $workspace->workflows()->whereKey($pending['workflow_id'])->first();
        $graph = $workflow?->draft();

        if (! $workflow || ! $graph || ! $graph->has($pending['node_id'])) {
            return;
        }

        $node = $graph->node($pending['node_id']);
        $this->workflows->saveDraft($workflow, $graph->withNode(new GraphNode(
            $node->id, $node->type, $node->name, [...$node->config, $pending['field'] => $credential->id], $node->retry, $node->position, $node->errorPath, $node->waitForAll, $node->masking,
        )));

        session()->flash('editor_select_node', $node->id);
    }

    /**
     * $base as a description that is free in the workspace, with (2), (3)
     * and so on added when it is taken.
     */
    protected function freeDescription(Workspace $workspace, string $base): string
    {
        $description = $base;

        for ($suffix = 2; $workspace->credentials()->where('description', $description)->exists(); $suffix++) {
            $description = "{$base} ({$suffix})";
        }

        return $description;
    }

    /**
     * A working access token for a connected setting, refreshed first when
     * it has expired or is about to.
     *
     * @throws RuntimeException when the setting isn't connected or the refresh fails
     */
    public function accessToken(Credential $credential): string
    {
        if (! OAuthCredentialType::isConnected($credential)) {
            throw new RuntimeException(__('oauth.not_connected', ['name' => $credential->description]));
        }

        if ($this->expiring($credential) && filled($credential->config['refresh_token'] ?? null)) {
            Cache::lock('oauth-refresh:'.$credential->id, 30)->block(15, function () use ($credential) {
                $credential->refresh();

                if ($this->expiring($credential)) {
                    $tokens = $this->requestTokens($credential, [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $credential->config['refresh_token'],
                    ]);

                    $credential->config = [...$credential->config, ...$this->connection($tokens, $credential->config['refresh_token'])];
                    $credential->save();
                }
            });
        }

        return (string) $credential->config['access_token'];
    }

    protected function expiring(Credential $credential): bool
    {
        $expiresAt = $credential->config['expires_at'] ?? null;

        return is_int($expiresAt) && $expiresAt - self::EXPIRY_MARGIN <= now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $tokens  the provider's token response
     * @return array<string, mixed>
     */
    protected function connection(array $tokens, ?string $previousRefreshToken): array
    {
        $expiresIn = $tokens['expires_in'] ?? null;

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $previousRefreshToken,
            'expires_at' => is_numeric($expiresIn) ? now()->getTimestamp() + (int) $expiresIn : null,
            'scopes' => $tokens['scope'] ?? null,
        ];
    }

    /**
     * Ask the provider's token endpoint for tokens.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    protected function requestTokens(Credential $credential, array $parameters): array
    {
        $type = $this->typeOf($credential);
        $url = $type->tokenUrl($credential->config);
        $request = Http::asForm()->acceptJson()->timeout(30);

        if ($type->hasUserUrls()) {
            $target = $this->guard->check($url, new AddressAllowlist($credential->workspace->internal_allowlist ?? []));
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
            $request->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]]]);
        }

        try {
            $response = $request->post($url, [
                ...$parameters,
                'client_id' => (string) $type->clientId($credential->config),
                'client_secret' => (string) $type->clientSecret($credential->config),
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('oauth.token_request_failed', ['message' => $e->getMessage()]));
        }

        $tokens = $response->json();

        if (! is_array($tokens) || ! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            $message = is_array($tokens) ? ($tokens['error_description'] ?? $tokens['error'] ?? null) : null;

            throw new RuntimeException(__('oauth.token_request_failed', ['message' => $message ?? 'HTTP '.$response->status()]));
        }

        return $tokens;
    }

    protected function typeOf(Credential $credential): OAuthCredentialType
    {
        $type = $credential->credentialType();

        if (! $type instanceof OAuthCredentialType) {
            throw new RuntimeException(__('oauth.not_an_oauth_setting', ['name' => $credential->description]));
        }

        return $type;
    }
}
