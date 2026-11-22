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
        return __('nodes.webhook_trigger.webhook');
    }

    public function description(): string
    {
        return __('nodes.webhook_trigger.inbound_http_request_arrives');
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
            new Field('method', __('common.method'), Field::SELECT, required: true, default: 'POST', options: ['POST' => 'POST', 'GET' => 'GET', 'PUT' => 'PUT', 'ANY' => __('nodes.webhook_trigger.any_method')]),
            new Field('verification', __('nodes.webhook_trigger.verify_requests_by'), Field::SELECT, required: true, default: 'none', options: [
                'none' => __('nodes.webhook_trigger.nothing_the_url_is_the'),
                'token' => __('nodes.webhook_trigger.shared_token'),
                'signature' => __('nodes.webhook_trigger.hmac_sha256_signature'),
                'ip' => __('nodes.webhook_trigger.ip_allowlist'),
            ]),
            new Field('secret', __('nodes.webhook_trigger.token_or_signing_secret'), Field::SECRET, required: true, description: __('nodes.webhook_trigger.secret_from_this_workspace'), visibleWhen: ['verification' => ['token', 'signature']]),
            new Field('header', __('nodes.webhook_trigger.header'), Field::TEXT, description: __('nodes.webhook_trigger.where_the_token_or_signature'), visibleWhen: ['verification' => ['token', 'signature']]),
            new Field('allowed_ips', __('nodes.webhook_trigger.allowed_ips'), Field::TEXTAREA, required: true, description: __('nodes.webhook_trigger.one_ip_address_or_cidr'), visibleWhen: ['verification' => 'ip']),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('method', __('common.method'), 'string'),
            new Output('headers', __('common.headers'), 'object'),
            new Output('query', __('common.query_parameters'), 'object'),
            new Output('body', __('common.body'), 'any', __('nodes.webhook_trigger.decoded_json_or_form_fields')),
            new Output('ip', __('nodes.webhook_trigger.caller_ip'), 'string'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }
}
