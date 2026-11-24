<?php

namespace App\Engine\Nodes\Actions;

use App\Credentials\Types\ChatWebhook;
use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Support\AddressAllowlist;
use App\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Posts a message to a chat channel through an incoming webhook saved in
 * the workspace's authentication settings: Slack (or Mattermost), Microsoft
 * Teams, Discord or Google Chat. Each service gets the payload it expects.
 */
class ChatMessage extends Node
{
    public function __construct(protected NetworkGuard $guard) {}

    public static function type(): string
    {
        return 'core.chat_message';
    }

    public function label(): string
    {
        return __('nodes.chat_message.chat_message');
    }

    public function description(): string
    {
        return __('nodes.chat_message.posts_a_message_to');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.messaging');
    }

    public function icon(): string
    {
        return 'chat-bubble-left-right';
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.chat_message.chat_webhook'), Field::CREDENTIAL, required: true, credentialTypes: ['chat_webhook'], description: __('nodes.chat_message.add_one_under_credentials')),
            new Field('message', __('nodes.chat_message.message'), Field::TEXTAREA, required: true, templated: true),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('service', __('nodes.chat_message.service'), 'string'),
            new Output('status', __('nodes.chat_message.http_status'), 'number'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $credential = $context->credential($context->input('credential'));

        if (! $credential || $credential->type !== 'chat_webhook') {
            return NodeResult::fail(__('nodes.chat_message.chat_webhook_not_found'), retryable: false);
        }

        $service = (string) ($credential->config['service'] ?? 'slack');
        $url = trim((string) ($credential->config['url'] ?? ''));
        $message = trim((string) $context->input('message'));

        if (! in_array($service, ChatWebhook::SERVICES, true)) {
            return NodeResult::fail(__('nodes.chat_message.unknown_service', ['service' => $service]), retryable: false);
        }

        if ($message === '') {
            return NodeResult::fail(__('nodes.chat_message.message_is_empty'), retryable: false);
        }

        try {
            $target = $this->guard->check($url, new AddressAllowlist($context->workspace()->internal_allowlist ?? []));
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

            $response = Http::timeout(30)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
                ])
                ->post($url, $this->payload($service, $message));
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.chat_message.could_not_reach', ['message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        $output = ['service' => $service, 'status' => $response->status()];

        if (! $response->successful()) {
            return NodeResult::fail(
                __('nodes.chat_message.service_answered_status', ['status' => $response->status(), 'body' => Str::limit(trim($response->body()), 300)]),
                retryable: $response->serverError() || $response->status() === 429,
                output: $output,
            );
        }

        return NodeResult::complete($output);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $service, string $message): array
    {
        return match ($service) {
            'discord' => ['content' => $message],
            'teams' => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [['type' => 'TextBlock', 'text' => $message, 'wrap' => true]],
                    ],
                ]],
            ],
            default => ['text' => $message],
        };
    }
}
