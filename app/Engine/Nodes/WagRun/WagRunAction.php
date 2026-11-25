<?php

namespace App\Engine\Nodes\WagRun;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use App\Support\RunChain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A call to the REST API under /api/v1, as the person whose personal access
 * token the workspace's API token setting holds. The setting reaches this
 * install unless it names another one, whose address is checked like an
 * HTTP call's. Subclasses add their fields and make one API call.
 */
abstract class WagRunAction extends Node
{
    public const CREDENTIAL_TYPE = 'wag_run';

    /**
     * Make the API call.
     *
     * @throws RuntimeException when a field is not usable
     * @throws ConnectionException when the install can't be reached
     */
    abstract protected function send(PendingRequest $api, NodeContext $context): Response;

    /**
     * The node's output, from the API's answer.
     *
     * @return array<string, mixed>
     */
    abstract protected function output(Response $response, NodeContext $context): array;

    /**
     * Fields after the API token.
     *
     * @return list<Field>
     */
    protected function actionInputs(): array
    {
        return [];
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return (string) config('app.name');
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.wag_run.api_token'), Field::CREDENTIAL, required: true, credentialTypes: [self::CREDENTIAL_TYPE], description: __('nodes.wag_run.api_token_help', ['app' => config('app.name')])),
            ...$this->actionInputs(),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $response = $this->send($this->request($context), $context);
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.wag_run.could_not_reach', ['app' => config('app.name'), 'message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        if (! $response->successful()) {
            return NodeResult::fail(
                __('nodes.wag_run.answered', ['app' => config('app.name'), 'status' => $response->status(), 'message' => $response->json('message') ?? Str::limit(trim($response->body()), 300)]),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        if (! is_array($response->json('data'))) {
            return NodeResult::fail(__('nodes.wag_run.unexpected_answer', ['app' => config('app.name')]), retryable: false);
        }

        return NodeResult::complete($this->output($response, $context));
    }

    /**
     * A request to the API of the install the node's setting names.
     *
     * @throws RuntimeException when the setting doesn't exist, or names an address workflows may not call
     */
    protected function request(NodeContext $context): PendingRequest
    {
        $credential = $context->credential($context->input('credential'));

        if (! $credential || $credential->type !== self::CREDENTIAL_TYPE) {
            throw new RuntimeException(__('nodes.wag_run.token_not_found'));
        }

        $request = Http::withToken(trim((string) ($credential->config['token'] ?? '')))
            ->acceptJson()
            ->timeout(30)
            ->withOptions(['allow_redirects' => false]);

        $url = rtrim(trim((string) ($credential->config['url'] ?? '')), '/');

        if ($url === '') {
            $url = rtrim((string) config('app.url'), '/');
        } elseif (! RunChain::isOwnHost((string) parse_url($url, PHP_URL_HOST))) {
            $target = app(NetworkGuard::class)->check($url, new AddressAllowlist($context->workspace()->internal_allowlist ?? []));
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

            $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]]]);
        }

        return $request->baseUrl($url.'/api/v1');
    }

    /**
     * A templated field's value as one segment of the API's path.
     *
     * @throws RuntimeException with $whenEmpty when the field is empty
     */
    protected function segment(NodeContext $context, string $field, string $whenEmpty): string
    {
        $value = trim((string) $context->input($field));

        return $value === '' ? throw new RuntimeException($whenEmpty) : $value;
    }
}
