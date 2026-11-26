<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workspace;
use App\Services\DataStore;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * An action on the workspace's data store: values that workflows keep
 * between runs. Subclasses add their fields and work on one key.
 */
abstract class StoreAction extends Node
{
    public function __construct(protected DataStore $store) {}

    /**
     * Do the node's work on one key and return its result.
     *
     * @throws RuntimeException when the store refuses, such as a value that is too large
     */
    abstract protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult;

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.compose.data');
    }

    public function paletteGroup(): array
    {
        return [$this->category(), __('nodes.store.store')];
    }

    /**
     * @return list<Field>
     */
    protected function keyFields(): array
    {
        return [
            new Field('key', __('common.key'), Field::TEXT, required: true, templated: true, description: __('nodes.store.key_help')),
            new Field('scope', __('nodes.store.shared_with'), Field::SELECT, required: true, default: 'workspace', description: __('nodes.store.shared_with_help'), options: [
                'workspace' => __('nodes.store.every_workflow_in_the_workspace'),
                'workflow' => __('nodes.store.this_workflow_only'),
            ]),
        ];
    }

    /**
     * @return list<Field>
     */
    protected function expiryFields(): array
    {
        return [
            new Field('expires_in', __('nodes.store.expires_after'), Field::NUMBER, templated: true, description: __('nodes.store.expires_after_help')),
            new Field('expires_unit', __('nodes.wait.unit'), Field::SELECT, default: 'days', options: ['minutes' => __('nodes.wait.minutes'), 'hours' => __('nodes.wait.hours'), 'days' => __('nodes.wait.days')]),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $key = DataStore::cleanKey($context->input('key'));
            $namespace = DataStore::namespaceFor($context->input('scope', 'workspace') === 'workflow', $context->execution->workflow_id);

            return $this->run($context, $context->workspace(), $namespace, $key);
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }
    }

    /**
     * When a value saved now expires, or null when it never does.
     *
     * @throws RuntimeException when the amount isn't a positive number
     */
    protected function expiresAt(NodeContext $context): ?CarbonInterface
    {
        $amount = $context->input('expires_in');

        if ($amount === null || $amount === '') {
            return null;
        }

        if (! is_numeric($amount) || $amount < 0) {
            throw new RuntimeException(__('nodes.store.expiry_not_a_number'));
        }

        return $amount == 0 ? null : now()->add((string) $context->input('expires_unit', 'days'), (float) $amount);
    }
}
