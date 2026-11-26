<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Models\Workspace;

/**
 * Reads the value saved under a key, or a default when there is none.
 */
class GetValue extends StoreAction
{
    public static function type(): string
    {
        return 'core.store_get';
    }

    public function label(): string
    {
        return __('nodes.store.get_value');
    }

    public function description(): string
    {
        return __('nodes.store.get_value_description');
    }

    public function icon(): string
    {
        return 'arrow-up-tray';
    }

    public function inputs(): array
    {
        return [
            ...$this->keyFields(),
            new Field('default', __('nodes.store.default'), Field::TEXT, templated: true, description: __('nodes.store.default_help')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('value', __('common.value'), 'any', __('nodes.store.read_value_help')),
            new Output('found', __('nodes.store.found'), 'boolean', __('nodes.store.found_help')),
        ];
    }

    protected function run(NodeContext $context, Workspace $workspace, string $namespace, string $key): NodeResult
    {
        $result = $this->store->get($workspace, $namespace, $key);

        if (! $result['found']) {
            $default = $context->input('default');
            $result['value'] = $default === '' ? null : $default;
        }

        return NodeResult::complete($result);
    }
}
