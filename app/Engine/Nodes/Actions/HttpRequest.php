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
        return __('nodes.http_request.http_call');
    }

    public function description(): string
    {
        return __('nodes.http_request.makes_an_http_request_optionally');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.http_request.integrations');
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
            new Field('method', __('common.method'), Field::SELECT, required: true, default: 'GET', options: array_combine(
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
            )),
            new Field('url', __('nodes.http_request.url'), Field::TEXT, required: true, templated: true),
            new Field('credential', __('common.authentication'), Field::CREDENTIAL, credentialTypes: ['http_basic', 'api_token']),
            new Field('query', __('common.query_parameters'), Field::KEY_VALUE, templated: true),
            new Field('headers', __('common.headers'), Field::KEY_VALUE, templated: true),
            new Field('body_type', __('common.body'), Field::SELECT, default: 'none', options: [
                'none' => __('nodes.http_request.none'), 'json' => __('nodes.http_request.json'), 'form' => __('common.form_fields'), 'raw' => __('nodes.http_request.raw_text'),
            ]),
            new Field('body', __('nodes.http_request.json_or_raw_body'), Field::TEXTAREA, templated: true),
            new Field('form', __('common.form_fields'), Field::KEY_VALUE, templated: true),
            new Field('timeout', __('common.timeout_seconds'), Field::NUMBER, default: 30),
            new Field('follow_redirects', __('nodes.http_request.follow_redirects'), Field::BOOLEAN, default: true),
            new Field('fail_on_error', __('nodes.http_request.fail_on_an_error_status'), Field::BOOLEAN, default: true),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('status', __('nodes.http_request.status_code'), 'number'),
            new Output('ok', __('nodes.http_request.succeeded_2xx'), 'boolean'),
            new Output('headers', __('common.headers'), 'object'),
            new Output('body', __('common.body'), 'any', __('nodes.http_request.decoded_json_when_the_response')),
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
                    return NodeResult::fail(__('nodes.http_request.too_many_redirects'), retryable: false);
                }

                $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->header('Location')));

                if ($response->status() === 303 || (in_array($response->status(), [301, 302], true) && $method !== 'GET' && $method !== 'HEAD')) {
                    $method = 'GET';
                    $options = [];
                }
            }
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.http_request.connection_failed_message', ['message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        $output = $this->responseOutput($response);

        if ($context->input('fail_on_error', true) && $response->status() >= 400) {
            return NodeResult::fail(
                __('nodes.http_request.http_status_reason', ['status' => $response->status(), 'reason' => $response->reason()]),
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
                throw new RuntimeException(__('nodes.http_request.authentication_key_cannot_be_used', ['key' => $credential->key]));
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
                return NodeResult::fail(__('nodes.http_request.json_body_is_not_valid', ['error' => json_last_error_msg()]), retryable: false);
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
