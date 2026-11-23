<?php

namespace App\Engine\Nodes\Actions;

use App\Credentials\HttpAuthenticator;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Services\WorkflowFiles;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use GuzzleHttp\Exception\RequestException;
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
                'none' => __('nodes.http_request.none'), 'json' => __('nodes.http_request.json'), 'form' => __('common.form_fields'), 'raw' => __('nodes.http_request.raw_text'), 'file' => __('nodes.http_request.a_file'),
            ]),
            new Field('file', __('nodes.http_request.file'), Field::TEXT, templated: true, description: __('nodes.http_request.file_help'), visibleWhen: ['body_type' => 'file']),
            new Field('body', __('nodes.http_request.json_or_raw_body'), Field::TEXTAREA, templated: true, visibleWhen: ['body_type' => ['json', 'raw']]),
            new Field('form', __('common.form_fields'), Field::KEY_VALUE, templated: true, description: __('nodes.http_request.form_files_help'), visibleWhen: ['body_type' => 'form']),
            new Field('timeout', __('common.timeout_seconds'), Field::NUMBER, default: 30),
            new Field('follow_redirects', __('nodes.http_request.follow_redirects'), Field::BOOLEAN, default: true),
            new Field('fail_on_error', __('nodes.http_request.fail_on_an_error_status'), Field::BOOLEAN, default: true),
            new Field('save_as_file', __('nodes.http_request.save_response_as_file'), Field::BOOLEAN, default: false, description: __('nodes.http_request.save_response_as_file_help')),
            new Field('allow_insecure', __('nodes.http_request.allow_insecure'), Field::BOOLEAN, default: false, description: __('nodes.http_request.allow_insecure_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('status', __('nodes.http_request.status_code'), 'number'),
            new Output('ok', __('nodes.http_request.succeeded_2xx'), 'boolean'),
            new Output('headers', __('common.headers'), 'object'),
            new Output('body', __('common.body'), 'any', __('nodes.http_request.decoded_json_when_the_response')),
            new Output('truncated', __('nodes.http_request.truncated'), 'boolean', __('nodes.http_request.truncated_help')),
            new Output('file', __('nodes.http_request.response_file'), 'file', __('nodes.http_request.response_file_help')),
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

        $download = $context->input('save_as_file') ? tempnam(sys_get_temp_dir(), 'wag-download-') : null;

        if ($download !== null) {
            $options['sink'] = $download;
            $limit = (int) config('workflows.files.max_bytes');
            $options['progress'] = function (int $expected, int $received) use ($limit) {
                if ($expected > $limit || $received > $limit) {
                    throw new RuntimeException(__('run_files.too_large', ['limit' => round($limit / 1048576).' MB']));
                }
            };
        }

        try {
            return $this->send($context, $method, $url, $allowlist, $options, $download);
        } finally {
            if ($download !== null && is_file($download)) {
                @unlink($download);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function send(NodeContext $context, string $method, string $url, AddressAllowlist $allowlist, array $options, ?string $download): NodeResult
    {
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
        } catch (RequestException $e) {
            return NodeResult::fail($e->getPrevious()?->getMessage() ?? $e->getMessage(), retryable: false);
        }

        try {
            $output = $this->responseOutput($context, $response, $url, $download);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

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
                'verify' => ! $context->input('allow_insecure', false),
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

        if ($context->input('body_type') === 'file') {
            return $this->fileBody($context);
        }

        if ($context->input('body_type') === 'form') {
            return $this->formBody($context);
        }

        return match ($context->input('body_type', 'none')) {
            'json' => ['json' => $body],
            'raw' => ['body' => is_scalar($body) ? (string) $body : json_encode($body)],
            default => [],
        };
    }

    /**
     * A text body over MAX_BODY_BYTES is cut to that size, and truncated
     * says so, so a workflow can refuse to pass a partial body on. A body
     * saved as a file, or one that is not text (an image, a zip), comes back
     * as file instead, with no body.
     *
     * @return array{status: int, ok: bool, headers: array<string, string>, body: mixed, truncated: bool, file: array<string, mixed>|null}
     */
    protected function responseOutput(NodeContext $context, Response $response, string $url, ?string $download): array
    {
        $headers = array_map(fn (array $values) => implode(', ', $values), $response->headers());
        $base = ['status' => $response->status(), 'ok' => $response->successful(), 'headers' => $headers];
        $name = $this->fileName($response, $url);
        $mime = trim(Str::before((string) $response->header('Content-Type'), ';')) ?: null;

        if ($download !== null) {
            return [...$base, 'body' => null, 'truncated' => false, 'file' => $context->storeFilePath($download, $name, $mime)];
        }

        $raw = $response->body();

        if ($raw !== '' && ! mb_check_encoding($raw, 'UTF-8')) {
            return [...$base, 'body' => null, 'truncated' => false, 'file' => $context->storeFile($raw, $name, $mime)];
        }

        $isJson = Str::contains((string) $response->header('Content-Type'), 'json');
        $body = $isJson ? ($response->json() ?? $raw) : $raw;

        $truncated = is_string($body) && strlen($body) > self::MAX_BODY_BYTES;

        if ($truncated) {
            $body = substr($body, 0, self::MAX_BODY_BYTES);
        }

        return [...$base, 'body' => $body, 'truncated' => $truncated, 'file' => null];
    }

    /**
     * The name a download is saved under: the one the server suggests, else
     * the last part of the URL's path.
     */
    protected function fileName(Response $response, string $url): string
    {
        $disposition = (string) $response->header('Content-Disposition');

        if (preg_match("/filename\*=(?:UTF-8'')?([^;]+)/i", $disposition, $match) || preg_match('/filename="?([^";]+)"?/i', $disposition, $match)) {
            return rawurldecode(trim($match[1], " \"'"));
        }

        return basename((string) parse_url($url, PHP_URL_PATH)) ?: 'download';
    }

    /**
     * A file as the whole request body, sent with its own content type
     * unless the node sets one.
     *
     * @return array<string, mixed>|NodeResult
     */
    protected function fileBody(NodeContext $context): array|NodeResult
    {
        try {
            $file = $context->files($context->input('file'))[0] ?? throw new RuntimeException(__('run_files.not_a_file'));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        $headers = array_change_key_case(array_filter((array) $context->input('headers', [])), CASE_LOWER);

        return [
            'body' => app(WorkflowFiles::class)->readStream($file),
            'headers' => isset($headers['content-type']) ? [] : ['Content-Type' => $file->mime],
        ];
    }

    /**
     * Form fields, sent as a multipart upload when any of them is a file.
     *
     * @return array<string, mixed>|NodeResult
     */
    protected function formBody(NodeContext $context): array|NodeResult
    {
        $fields = array_filter((array) $context->input('form', []), fn ($value, $key) => $key !== '', ARRAY_FILTER_USE_BOTH);

        if (! array_any($fields, fn (mixed $value) => WorkflowFiles::isReference($value))) {
            return ['form_params' => $fields];
        }

        $parts = [];

        foreach ($fields as $name => $value) {
            if (! WorkflowFiles::isReference($value)) {
                $parts[] = ['name' => (string) $name, 'contents' => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value)];

                continue;
            }

            $file = $context->file($value);

            if ($file === null) {
                return NodeResult::fail(__('run_files.file_not_found'), retryable: false);
            }

            $parts[] = ['name' => (string) $name, 'contents' => app(WorkflowFiles::class)->readStream($file), 'filename' => $file->name, 'headers' => ['Content-Type' => $file->mime]];
        }

        return ['multipart' => $parts];
    }
}
