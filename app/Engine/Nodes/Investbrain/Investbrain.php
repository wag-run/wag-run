<?php

namespace App\Engine\Nodes\Investbrain;

use App\Models\Workspace;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What every Investbrain node shares: a request to the REST API under /api
 * with one of the workspace's Investbrain API token settings, reading every
 * page of a list, and reading a portfolio or a symbol.
 *
 * The setting reaches Investbrain Cloud unless it names a self-hosted
 * install, and either address is checked like an HTTP call's.
 */
final class Investbrain
{
    public const CREDENTIAL_TYPE = 'investbrain';

    public const CLOUD_URL = 'https://investbra.in';

    /**
     * The most items Investbrain lists on one page.
     */
    public const PAGE_SIZE = 100;

    /**
     * @throws RuntimeException when the setting doesn't exist, or names an address workflows may not call
     */
    public static function request(Workspace $workspace, mixed $credentialId): PendingRequest
    {
        $credential = is_string($credentialId) && Str::isUuid($credentialId)
            ? $workspace->credentials()->whereKey($credentialId)->first()
            : null;

        if (! $credential || $credential->type !== self::CREDENTIAL_TYPE) {
            throw new RuntimeException(__('nodes.investbrain.token_not_found'));
        }

        $url = rtrim(trim((string) ($credential->config['url'] ?? '')), '/') ?: self::CLOUD_URL;
        $target = app(NetworkGuard::class)->check($url, new AddressAllowlist($workspace->internal_allowlist ?? []));
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        return Http::baseUrl($url.'/api')
            ->withToken(trim((string) ($credential->config['token'] ?? '')))
            ->acceptJson()
            ->timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
            ]);
    }

    /**
     * The JSON object in a successful answer.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException when Investbrain refused
     * @throws RuntimeException when it didn't answer with JSON
     */
    public static function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new RequestException($response);
        }

        $body = $response->json();

        return is_array($body) ? $body : throw new RuntimeException(__('nodes.investbrain.unexpected_answer'));
    }

    /**
     * Up to $limit items of a list, read a page at a time.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws RequestException when Investbrain refused
     * @throws RuntimeException when it didn't answer with a list
     */
    public static function list(PendingRequest $api, string $path, array $query, int $limit): array
    {
        $items = [];

        for ($page = 1; count($items) < $limit; $page++) {
            $body = self::json($api->get($path, [...$query, 'page' => $page, 'itemsPerPage' => min(self::PAGE_SIZE, $limit)]));

            if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                throw new RuntimeException(__('nodes.investbrain.unexpected_answer'));
            }

            $items = [...$items, ...$body['data']];

            if ($body['data'] === [] || $page >= (int) ($body['meta']['last_page'] ?? 1)) {
                break;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * A portfolio's id, from the id or the portfolio's page in Investbrain,
     * or null when the value is empty.
     *
     * @throws RuntimeException when it holds no portfolio id
     */
    public static function portfolio(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $match)) {
            throw new RuntimeException(__('nodes.investbrain.invalid_portfolio', ['portfolio' => $value]));
        }

        return strtolower($match[0]);
    }

    /**
     * A ticker symbol as Investbrain stores it, or null when the value is empty.
     */
    public static function symbol(mixed $value): ?string
    {
        $symbol = strtoupper(trim((string) $value));

        return $symbol === '' ? null : $symbol;
    }

    public static function failure(Response $response): string
    {
        if ($response->redirect()) {
            return __('nodes.investbrain.redirected', ['status' => $response->status(), 'location' => $response->header('Location')]);
        }

        return __('nodes.investbrain.answered', ['status' => $response->status(), 'message' => $response->json('message') ?? Str::limit(trim($response->body()), 300)]);
    }
}
