<?php

namespace App\Engine;

use App\Jobs\PollTrigger;
use App\Models\Workspace;
use App\Services\TriggerSync;
use Illuminate\Http\Request;

/**
 * A trigger node that starts the workflow on another app's events, such as
 * a new tag in a GitHub repository. It hears about them one of two ways,
 * chosen per node:
 *
 * - Polling: every few minutes, poll() asks the app what is new. The first
 *   poll after publishing only records what already exists.
 * - Webhook: when the workflow is published, subscribe() registers a webhook
 *   with the app, and receive() turns each request into events. Changing or
 *   unpublishing the trigger calls unsubscribe().
 *
 * {@see TriggerSync} keeps the subscriptions in step with the
 * published version; {@see PollTrigger} runs the polls.
 */
interface EventTrigger
{
    public const POLL = 'poll';

    public const WEBHOOK = 'webhook';

    /**
     * How this node hears about events: POLL or WEBHOOK.
     *
     * @param  array<string, mixed>  $config
     */
    public function listensBy(array $config): string;

    /**
     * Minutes between polls.
     *
     * @param  array<string, mixed>  $config
     */
    public function pollEvery(array $config): int;

    /**
     * Ask for events since the last poll.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>|null  $state  what the last poll kept; null on the first
     * @return array{events: list<array<string, mixed>>, state: array<string, mixed>} each event is a run's trigger input, oldest first
     */
    public function poll(array $config, Workspace $workspace, ?array $state): array;

    /**
     * Register a webhook that sends events to $url, signed with $secret.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed> what unsubscribe() needs to remove it
     */
    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array;

    /**
     * @param  array<string, mixed>  $config  the config it was subscribed with
     * @param  array<string, mixed>  $subscription  what subscribe() returned
     */
    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void;

    /**
     * The events in a webhook request: an empty list for one to ignore, or
     * null when the request fails verification.
     *
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>|null
     */
    public function receive(array $config, Request $request, string $secret): ?array;
}
