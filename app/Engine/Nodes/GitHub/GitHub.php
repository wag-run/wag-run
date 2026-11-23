<?php

namespace App\Engine\Nodes\GitHub;

use App\Credentials\Types\GitHubOAuth;
use App\Models\Workspace;
use App\Services\OAuthConnections;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What every GitHub node shares: a request to the REST API as the workspace's
 * connected GitHub account, and reading a repository name.
 */
final class GitHub
{
    /**
     * @throws RuntimeException when the setting doesn't exist, isn't a GitHub connection, or isn't connected
     */
    public static function request(Workspace $workspace, mixed $credentialId): PendingRequest
    {
        $credential = is_string($credentialId) && Str::isUuid($credentialId)
            ? $workspace->credentials()->whereKey($credentialId)->first()
            : null;

        if (! $credential || $credential->type !== 'github') {
            throw new RuntimeException(__('nodes.github.connection_not_found'));
        }

        return Http::baseUrl(GitHubOAuth::API)
            ->withToken(app(OAuthConnections::class)->accessToken($credential))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(30);
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
