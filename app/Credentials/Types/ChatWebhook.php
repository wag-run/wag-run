<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * An incoming-webhook URL that Chat message nodes post to, for Slack (and
 * Slack-compatible apps such as Mattermost), Microsoft Teams, Discord or
 * Google Chat. The URL is the secret: anyone who has it can post.
 */
class ChatWebhook extends CredentialType
{
    public const SERVICES = ['slack', 'teams', 'discord', 'google_chat'];

    public function key(): string
    {
        return 'chat_webhook';
    }

    public function label(): string
    {
        return __('credentials.chat_webhook.chat_webhook');
    }

    public function description(): string
    {
        return __('credentials.chat_webhook.an_incoming_webhook_url');
    }

    public function fields(): array
    {
        return [
            CredentialField::select('service', __('credentials.chat_webhook.service'), [
                'slack' => __('credentials.chat_webhook.slack'),
                'teams' => __('credentials.chat_webhook.teams'),
                'discord' => __('credentials.chat_webhook.discord'),
                'google_chat' => __('credentials.chat_webhook.google_chat'),
            ], default: 'slack'),
            CredentialField::secret('url', __('credentials.chat_webhook.webhook_url'), help: __('credentials.chat_webhook.the_url_the_chat_app')),
        ];
    }
}
