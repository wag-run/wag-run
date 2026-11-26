<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Models\Workspace;

/**
 * Removes the value saved under a key.
 */
class DeleteValue extends StoreAction
{
    public static function type(): string
    {
        return 'core.store_delete';
    }

    public function label(): string
    {
        return __('nodes.store.delete_value');
    }

    public function description(): string
    {
        return __('nodes.store.delete_value_description');
    }

    public function icon(): string
    {
        return 'trash';
    }

    public function inputs(): array
    {
        return $this->keyFields();
    }

    public function outputs(array $config = []): array
    {
        return [new Output('deleted', __('nodes.store.deleted'), 'boolean', __('nodes.store.deleted_help'))];
    }

    protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult
    {
        return NodeResult::complete(['deleted' => $this->store->forget($workspace, $namespace, $key)]);
    }
}
