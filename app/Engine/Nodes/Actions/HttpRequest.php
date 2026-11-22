<?php

namespace App\Engine\Nodes\Actions;

use App\Credentials\HttpAuthenticator;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes an HTTP request, optionally authenticated with a workspace setting.
 * Internal addresses are refused unless allowlisted, on every redirect hop,
 * and the connection is pinned to the address that was checked.
 */
class HttpRequest extends Node
{
    public const MAX_REDIRECTS = 5;

    public const MAX_BODY_BYTES = 1_048_576;

    public function __construct(protected NetworkGuard $guard) {}

    public static function type(): string
    {
        return 'core.http_request';
    }

    public function label(): string
    {
        return __('HTTP call');
    }

    public function description(): string
    {
        return __('Makes an HTTP request, optionally using a workspace authentication setting.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Integrations');
    }

    public function icon(): string
    {
        return 'globe-alt';
    }

    public function timeout(): int
    {
        return 360;
    }

    public function inputs(): array
    {
        return [
            new Field('method', __('Method'), Field::SELECT, required: true, default: 'GET', options: array_combine(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            )),
            new Field('url', __('URL'), Field::TEXT, required: true, templated: true),
            new Field('credential', __('Authentication'), Field::CREDENTIAL, credentialTypes: ['http_basic', 'api_token']),
            new Field('query', __('Query parameters'), Field::KEY_VALUE, templated: true),
            new Field('headers', __('Headers'), Field::KEY_VALUE, templated: true),
            new Field('body_type', __('Body'), Field::SELECT, default: 'none', options: [
                'none' => __('None'), 'json' => __('JSON'), 'form' => __('Form fields'), 'raw' => __('Raw text'),
            ]),
            new Field('body', __('JSON or raw body'), Field::TEXTAREA, templated: true),
            new Field('form', __('Form fields'), Field::KEY_VALUE, templated: true),
            new Field('timeout', __('Timeout (seconds)'), Field::NUMBER, default: 30),
            new Field('follow_redirects', __('Follow redirects'), Field::BOOLEAN, default: true),
            new Field('fail_on_error', __('Fail on an error status (4xx or 5xx)'), Field::BOOLEAN, default: true),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('status', __('Status code'), 'number'),
            new Output('ok', __('Succeeded (2xx)'), 'boolean'),
            new Output('headers', __('Headers'), 'object'),
            new Output('body', __('Body'), 'any', __('Decoded JSON when the response is JSON, else text.')),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $method = strtoupper((string) $context->input('method', 'GET'));
        $url = trim((string) $context->input('url'));
        $allowlist = new AddressAllowlist($context->workspace()->internal_allowlist ?? []);
        $options = $this->bodyOptions($context);

        if ($options instanceof NodeResult) {
            return $options;
        }

        try {
            for ($hop = 0; ; $hop++) {
                $target = $this->guard->check($url, $allowlist);
                $response = $this->request($context, $target)->send($method, $url, $options);

                if (! $context->input('follow_redirects', true) || ! $response->redirect() || ! $response->header('Location')) {
                    break;
                }

                if ($hop >= self::MAX_REDIRECTS) {
                    return NodeResult::fail(__('Too many redirects.'), retryable: false);
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                if ($response->status() === 303 || (in_array($response->status(), [301, 302], true) && $method !== 'GET' && $method !== 'HEAD')) {
                    $method = 'GET';
                    $options = [];
                }
            }
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('Connection failed: :message', ['message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        $output = $this->responseOutput($response);

        if ($context->input('fail_on_error', true) && $response->status() >= 400) {
            return NodeResult::fail(
                __('HTTP :status :reason', ['status' => $response->status(), 'reason' => $response->reason()]),
                retryable: $response->status() >= 500 || $response->status() === 429,
                output: $output,
            );
        }

        return NodeResult::complete($output);
    }

    /**
     * @param  array{host: string, port: int, ip: string}  $target
     */
    protected function request(NodeContext $context, array $target): PendingRequest
    {
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        $request = Http::timeout(max(1, min(300, (int) $context->input('timeout', 30))))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
            ])
            ->withHeaders(array_filter((array) $context->input('headers', []), fn ($value, $key) => $key !== '', ARRAY_FILTER_USE_BOTH));

        if ($query = array_filter((array) $context->input('query', []), fn ($value, $key) => $key !== '', ARRAY_FILTER_USE_BOTH)) {
            $request->withQueryParameters($query);
        }

        $credential = $context->credential($context->input('credential'));

        if ($credential) {
            $type = $credential->credentialType();

            if (! $type instanceof HttpAuthenticator) {
                throw new RuntimeException(__('Authentication ":key" cannot be used for HTTP calls.', ['key' => $credential->key]));
            }

            $request = $type->authenticate($request, $credential->config);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>|NodeResult
     */
    protected function bodyOptions(NodeContext $context): array|NodeResult
    {
        $body = $context->input('body');

        if ($context->input('body_type') === 'json' && is_string($body)) {
            $body = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return NodeResult::fail(__('The JSON body is not valid JSON: :error', ['error' => json_last_error_msg()]), retryable: false);
            }
        }

        return match ($context->input('body_type', 'none')) {
            'json' => ['json' => $body],
            'form' => ['form_params' => (array) $context->input('form', [])],
            'raw' => ['body' => is_scalar($body) ? (string) $body : json_encode($body)],
            default => [],
        };
    }

    /**
     * @return array{status: int, ok: bool, headers: array<string, string>, body: mixed}
     */
    protected function responseOutput(Response $response): array
    {
        $raw = $response->body();
        $isJson = Str::contains((string) $response->header('Content-Type'), 'json');
        $body = $isJson ? ($response->json() ?? $raw) : $raw;

        if (is_string($body) && strlen($body) > self::MAX_BODY_BYTES) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        return [
            'status' => $response->status(),
            'ok' => $response->successful(),
            'headers' => array_map(fn (array $values) => implode(', ', $values), $response->headers()),
            'body' => $body,
        ];
    }
}
