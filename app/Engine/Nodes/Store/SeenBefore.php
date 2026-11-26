<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Models\Workspace;

/**
 * Checks a key and records it in one step that is safe when runs overlap,
 * then follows seen when it was recorded already or new when this run
 * recorded it first. For "only post this once" and "don't email twice a day".
 */
class SeenBefore extends StoreAction
{
    public static function type(): string
    {
        return 'core.store_seen_before';
    }

    public function label(): string
    {
        return __('nodes.store.seen_before');
    }

    public function description(): string
    {
        return __('nodes.store.seen_before_description');
    }

    public function icon(): string
    {
        return 'eye';
    }

    public function inputs(): array
    {
        return [...$this->keyFields(), ...$this->expiryFields()];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('seen', __('nodes.store.seen'), 'boolean', __('nodes.store.seen_help'))];
    }

    public function ports(array $config = []): array
    {
        return ['new', 'seen'];
    }

    public function portLabel(string $port, array $config = []): ?string
    {
        if ($port === 'new') {
            return __('nodes.store.new');
        }

        if ($port === 'seen') {
            return __('nodes.store.seen');
        }

        return null;
    }

    protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult
    {
        $seen = ! $this->store->claim($workspace, $namespace, $key, $this->expiresAt($context));

        return NodeResult::complete(['seen' => $seen], $seen ? 'seen' : 'new');
    }
}
