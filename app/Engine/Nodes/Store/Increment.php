<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Models\Workspace;

/**
 * Adds to a number saved under a key, starting from 0, in one step that
 * is safe when runs overlap. Use a negative amount to subtract.
 */
class Increment extends StoreAction
{
    public static function type(): string
    {
        return 'core.store_increment';
    }

    public function label(): string
    {
        return __('nodes.store.increment');
    }

    public function description(): string
    {
        return __('nodes.store.increment_description');
    }

    public function icon(): string
    {
        return 'plus-circle';
    }

    public function inputs(): array
    {
        return [
            ...$this->keyFields(),
            new Field('by', __('nodes.store.by'), Field::NUMBER, required: true, default: 1, templated: true),
            ...$this->expiryFields(),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('value', __('nodes.store.new_total'), 'number', __('nodes.store.new_total_help'))];
    }

    protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult
    {
        $by = $context->input('by', 1);

        if (! is_numeric($by)) {
            return NodeResult::fail(__('nodes.store.by_not_a_number'), retryable: false);
        }

        return NodeResult::complete(['value' => $this->store->increment($workspace, $namespace, $key, $by + 0, $this->expiresAt($context))]);
    }
}
