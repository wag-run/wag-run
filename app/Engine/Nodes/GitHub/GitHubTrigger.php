<?php

namespace App\Engine\Nodes\GitHub;

use App\Engine\Definitions\Field;
use App\Engine\EventTrigger;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workspace;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A trigger on a GitHub repository's events. It polls the REST API every few
 * minutes, which works from behind a firewall, or registers a webhook on the
 * repository, which is instant but needs GitHub to reach this install.
 *
 * A poll remembers the newest items it has seen, so it starts a run only
 * for new ones, and sends the last ETag, so an unchanged answer doesn't
 * count against GitHub's rate limit.
 */
abstract class GitHubTrigger extends Node implements EventTrigger
{
    protected const MEMORY = 300;

    /**
     * The webhook events to subscribe to.
     *
     * @return list<string>
     */
    abstract protected function webhookEvents(): array;

    /**
     * The REST API path to poll, and its query.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: array<string, mixed>}
     */
    abstract protected function pollRequest(string $repository, array $config): array;

    /**
     * The events in a poll's answer, newest first, each with an id that
     * stays the same between polls.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{id: string, event: array<string, mixed>}>
     */
    abstract protected function fromPoll(mixed $body, string $repository, array $config): array;

    /**
     * The run input for one webhook delivery, or null to ignore it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    abstract protected function fromWebhook(string $event, array $payload, string $repository, array $config): ?array;

    /**
     * Fields between the repository and how to listen.
     *
     * @return list<Field>
     */
    protected function extraInputs(): array
    {
        return [];
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'code-bracket-square';
    }

    public function category(): string
    {
        return __('common.event_in_other_app');
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.github.github_account'), Field::CREDENTIAL, required: true, credentialTypes: ['github'], description: __('nodes.github.connect_one_under_authentication')),
            new Field('repository', __('nodes.github.repository'), Field::TEXT, required: true, description: __('nodes.github.owner_and_name')),
            ...$this->extraInputs(),
            new Field('listen', __('nodes.github.listen_by'), Field::SELECT, required: true, default: EventTrigger::POLL, options: [
                EventTrigger::POLL => __('nodes.github.checking_every_few_minutes'),
                EventTrigger::WEBHOOK => __('nodes.github.a_webhook_on_the_repository'),
            ], description: __('nodes.github.listen_by_help')),
            new Field('poll_minutes', __('nodes.github.check_every_minutes'), Field::NUMBER, default: 5, visibleWhen: ['listen' => EventTrigger::POLL]),
        ];
    }

    public function configProblems(array $config): array
    {
        try {
            GitHub::repository($config['repository'] ?? '');
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }

    public function listensBy(array $config): string
    {
        return ($config['listen'] ?? EventTrigger::POLL) === EventTrigger::WEBHOOK ? EventTrigger::WEBHOOK : EventTrigger::POLL;
    }

    public function pollEvery(array $config): int
    {
        return max(1, (int) ($config['poll_minutes'] ?? 5));
    }

    public function poll(array $config, Workspace $workspace, ?array $state): array
    {
        $repository = GitHub::repository($config['repository'] ?? '');
        [$path, $query] = $this->pollRequest($repository, $config);
        $request = GitHub::request($workspace, $config['credential'] ?? null);

        if ($state !== null && filled($state['etag'] ?? null)) {
            $request->withHeaders(['If-None-Match' => $state['etag']]);
        }

        $response = $request->get($path, $query);

        if ($response->status() === 304 && $state !== null) {
            return ['events' => [], 'state' => $state];
        }

        if (! $response->successful()) {
            throw new RuntimeException(GitHub::failure($response));
        }

        $items = $this->fromPoll($response->json(), $repository, $config);
        $ids = array_column($items, 'id');
        $seen = $state === null ? $ids : (array) ($state['seen'] ?? []);
        $new = array_reverse(array_filter($items, fn (array $item) => ! in_array($item['id'], $seen, true)));

        return [
            'events' => array_column($new, 'event'),
            'state' => [
                'seen' => array_slice(array_values(array_unique([...$ids, ...$seen])), 0, self::MEMORY),
                'etag' => $response->header('ETag') ?: null,
            ],
        ];
    }

    public function subscribe(array $config, Workspace $workspace, string $url, string $secret): array
    {
        $repository = GitHub::repository($config['repository'] ?? '');

        $response = GitHub::request($workspace, $config['credential'] ?? null)->post("/repos/{$repository}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => $this->webhookEvents(),
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(GitHub::failure($response));
        }

        return ['hook_id' => $response->json('id')];
    }

    public function unsubscribe(array $config, Workspace $workspace, array $subscription): void
    {
        $repository = GitHub::repository($config['repository'] ?? '');
        $response = GitHub::request($workspace, $config['credential'] ?? null)->delete("/repos/{$repository}/hooks/{$subscription['hook_id']}");

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(GitHub::failure($response));
        }
    }

    public function receive(array $config, Request $request, string $secret): ?array
    {
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('X-Hub-Signature-256', ''))) {
            return null;
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = json_decode($request->getContent(), true);
        $repository = GitHub::repository($config['repository'] ?? '');

        if ($event === 'ping' || ! is_array($payload) || strcasecmp((string) ($payload['repository']['full_name'] ?? ''), $repository) !== 0) {
            return [];
        }

        $run = $this->fromWebhook($event, $payload, $repository, $config);

        return $run === null ? [] : [$run];
    }
}
