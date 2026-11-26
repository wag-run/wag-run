<?php

namespace App\Engine\Nodes\Store;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Enums\ValueOperation;
use App\Enums\ValueType;
use App\Services\DataStore;
use RuntimeException;

/**
 * Changes an entry of the workspace's data store: Set replaces its value,
 * Append adds to a String or an Array, and Increment adds to a Number. In a
 * collection it changes the one item under Item key. Reading is by
 * placeholder, {{ store.<key> }}, so there is no node to read.
 *
 * The entry has to exist and not be read-only; a write never creates one.
 */
class UpdateDataStore extends Node
{
    public function __construct(protected DataStore $store) {}

    public static function type(): string
    {
        return 'core.store_update';
    }

    public function label(): string
    {
        return __('nodes.store.update_data_store');
    }

    public function description(): string
    {
        return __('nodes.store.update_data_store_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.compose.data');
    }

    public function icon(): string
    {
        return 'circle-stack';
    }

    public function inputs(): array
    {
        return [
            new Field('entry', __('nodes.store.entry'), Field::STORE_ENTRY, required: true, description: __('nodes.store.entry_help')),
            new Field('item_key', __('nodes.store.item_key'), Field::TEXT, templated: true, description: __('nodes.store.item_key_help'), visibleWhen: ['entry.collection' => 'yes']),
            new Field('operation', __('nodes.store.operation'), Field::SELECT, required: true, default: ValueOperation::Set->value,
                options: collect(ValueOperation::cases())->mapWithKeys(fn (ValueOperation $operation) => [$operation->value => $operation->label()])->all(),
                optionsWhen: [
                    ValueOperation::Append->value => ['entry.type' => [ValueType::String->value, ValueType::Array->value]],
                    ValueOperation::Increment->value => ['entry.type' => ValueType::Number->value],
                ]),
            new Field('value', __('common.value'), Field::TEXTAREA, templated: true, description: __('nodes.store.value_help'), visibleWhen: ['operation' => [ValueOperation::Set->value, ValueOperation::Append->value]]),
            new Field('amount', __('nodes.store.amount'), Field::NUMBER, default: 1, templated: true, description: __('nodes.store.amount_help'), visibleWhen: ['operation' => ValueOperation::Increment->value]),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('entry', __('nodes.store.entry'), 'string'),
            new Output('item_key', __('nodes.store.item_key'), 'string', __('nodes.store.item_key_output_help')),
            new Output('operation', __('nodes.store.operation'), 'string'),
            new Output('before', __('nodes.store.value_before'), 'any', __('nodes.store.value_before_help')),
            new Output('after', __('nodes.store.value_after'), 'any', __('nodes.store.value_after_help')),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $key = (string) $context->input('entry');
        $entry = $key === '' ? null : $this->store->entry($context->workspace(), $key);

        if (! $entry) {
            return NodeResult::fail(__('nodes.store.entry_not_found', ['key' => $key]), retryable: false);
        }

        $operation = ValueOperation::tryFrom((string) $context->input('operation', ValueOperation::Set->value)) ?? ValueOperation::Set;
        $operand = $operation === ValueOperation::Increment ? $context->input('amount', 1) : $context->input('value');

        try {
            $change = $this->store->write($entry, $operation, $operand, $context->input('item_key'));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        return NodeResult::complete([
            'entry' => $entry->key,
            'item_key' => $entry->is_collection ? DataStore::cleanItemKey($context->input('item_key')) : null,
            'operation' => $operation->value,
            'before' => $change['before'],
            'after' => $change['after'],
        ]);
    }
}
