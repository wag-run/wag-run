<?php

namespace App\Engine\Nodes\Triggers;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Starts the workflow when an HTTP request arrives at its unique URL.
 * Requests can be verified by HMAC signature, a shared token, or an IP
 * allowlist. The request itself is handled by WebhookController.
 */
class WebhookTrigger extends Node
{
    public static function type(): string
    {
        return 'core.webhook_trigger';
    }

    public function label(): string
    {
        return __('Webhook');
    }

    public function description(): string
    {
        return __('An inbound HTTP request arrives.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'bolt';
    }

    public function inputs(): array
    {
        return [
            new Field('method', __('Method'), Field::SELECT, required: true, default: 'POST', options: ['POST' => 'POST', 'GET' => 'GET', 'PUT' => 'PUT', 'ANY' => __('Any method')]),
            new Field('verification', __('Verify requests by'), Field::SELECT, required: true, default: 'none', options: [
                'none' => __('Nothing (the URL is the secret)'),
                'token' => __('Shared token'),
                'signature' => __('HMAC-SHA256 signature'),
                'ip' => __('IP allowlist'),
            ]),
            new Field('secret', __('Token or signing secret'), Field::SECRET, description: __('A secret from this workspace.')),
            new Field('header', __('Header'), Field::TEXT, description: __('Where the token or signature is sent. Defaults to X-Webhook-Token or X-Signature.')),
            new Field('allowed_ips', __('Allowed IPs'), Field::TEXTAREA, description: __('One IP address or CIDR range per line.')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('method', __('Method'), 'string'),
            new Output('headers', __('Headers'), 'object'),
            new Output('query', __('Query parameters'), 'object'),
            new Output('body', __('Body'), 'any', __('Decoded JSON or form fields, else the raw text.')),
            new Output('ip', __('Caller IP'), 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }
}
