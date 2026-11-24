<?php

namespace App\Engine\Nodes\GitHub;

use App\Credentials\Types\GitHubOAuth;
use App\Models\Credential;
use App\Models\Workspace;
use App\Services\OAuthConnections;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What every GitHub node shares: a request to the REST API with one of the
 * workspace's GitHub settings, and reading a repository name.
 *
 * A setting is a GitHub account connected through OAuth, a personal access
 * token, or a GitHub App installation.
 */
final class GitHub
{
    /**
     * The authentication types a GitHub node accepts.
     */
    public const CREDENTIAL_TYPES = ['github', 'github_token', 'github_app'];

    /**
     * How long before an installation token expires that a new one is
     * asked for, in seconds.
     */
    private const INSTALLATION_TOKEN_MARGIN = 300;

    /**
     * @throws RuntimeException when the setting doesn't exist, isn't a GitHub setting, or can't give a token
     */
    public static function request(Workspace $workspace, mixed $credentialId): PendingRequest
    {
        $credential = is_string($credentialId) && Str::isUuid($credentialId)
            ? $workspace->credentials()->whereKey($credentialId)->first()
            : null;

        if (! $credential || ! in_array($credential->type, self::CREDENTIAL_TYPES, true)) {
            throw new RuntimeException(__('nodes.github.connection_not_found'));
        }

        $token = match ($credential->type) {
            'github_token' => (string) ($credential->config['token'] ?? ''),
            'github_app' => self::installationToken($credential),
            default => app(OAuthConnections::class)->accessToken($credential),
        };

        return Http::baseUrl(GitHubOAuth::API)
            ->withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30);
    }

    /**
     * A token for a GitHub App's installation, good for an hour. It is
     * asked for with a JSON Web Token signed with the app's private key,
     * and kept in the cache until shortly before it expires.
     *
     * @throws RuntimeException when the settings are incomplete or GitHub refuses
     */
    public static function installationToken(Credential $credential): string
    {
        $appId = trim((string) ($credential->config['app_id'] ?? ''));
        $installationId = trim((string) ($credential->config['installation_id'] ?? ''));
        $privateKey = str_replace(['\\n', "\r\n"], "\n", trim((string) ($credential->config['private_key'] ?? '')));

        if ($appId === '' || ! ctype_digit($installationId)) {
            throw new RuntimeException(__('nodes.github.app_settings_incomplete', ['name' => $credential->description]));
        }

        $cacheKey = 'github-app-token:'.$credential->id.':'.hash('sha256', $appId.'|'.$installationId.'|'.$privateKey);
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl(GitHubOAuth::API)
            ->withToken(self::appJwt($appId, $privateKey, $credential))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30)
            ->post("/app/installations/{$installationId}/access_tokens");

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException(__('nodes.github.app_token_refused', ['name' => $credential->description, 'message' => self::failure($response)]));
        }

        $expiresAt = strtotime((string) $response->json('expires_at')) ?: now()->addHour()->getTimestamp();
        $seconds = $expiresAt - now()->getTimestamp() - self::INSTALLATION_TOKEN_MARGIN;

        if ($seconds > 0) {
            Cache::put($cacheKey, $token, $seconds);
        }

        return $token;
    }

    /**
     * A JSON Web Token that identifies the GitHub App, signed RS256 with its
     * private key. It is dated a minute back for clock drift and lasts nine
     * minutes, under GitHub's limit of ten.
     *
     * @throws RuntimeException when the private key can't be read
     */
    private static function appJwt(string $appId, string $privateKey, Credential $credential): string
    {
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new RuntimeException(__('nodes.github.app_private_key_invalid', ['name' => $credential->description]));
        }

        $now = now()->getTimestamp();
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId]));

        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException(__('nodes.github.app_private_key_invalid', ['name' => $credential->description]));
        }

        return $unsigned.'.'.$encode($signature);
    }

    /**
     * "owner/name" from a repository name or its GitHub URL.
     *
     * @throws RuntimeException when it isn't one
     */
    public static function repository(mixed $value): string
    {
        $repository = trim((string) $value);
        $repository = (string) Str::of($repository)->after('github.com/')->before('.git')->trim('/');

        if (! preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            throw new RuntimeException(__('nodes.github.invalid_repository', ['repository' => trim((string) $value)]));
        }

        return $repository;
    }

    public static function failure(Response $response): string
    {
        return __('nodes.github.github_answered', ['status' => $response->status(), 'message' => $response->json('message') ?? Str::limit(trim($response->body()), 300)]);
    }
}
