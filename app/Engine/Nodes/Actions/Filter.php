<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Templates\TemplateError;
use App\Enums\NodeKind;

/**
 * Keeps the items of a list that match a condition. The condition is one
 * {{ }} placeholder checked once per item, with "item" set to the item and
 * "index" to its position, such as {{ item.amount > 100 }}. Inside a For
 * each, the loop's own item is still loop.item.
 */
class Filter extends Node
{
    public static function type(): string
    {
        return 'core.filter';
    }

    public function label(): string
    {
        return __('nodes.filter.filter');
    }

    public function description(): string
    {
        return __('nodes.filter.keeps_the_items_of_a');
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
        return 'funnel';
    }

    public function inputs(): array
    {
        return [
            new Field('items', __('nodes.filter.list'), Field::TEXT, required: true, templated: true, description: __('nodes.filter.one_placeholder_for_a_list')),
            new Field('condition', __('nodes.conditional.condition'), Field::TEXT, required: true, description: __('nodes.filter.checked_for_each_item')),
        ];
    }

    public function configProblems(array $config): array
    {
        $problems = [];

        foreach (['items' => 'nodes.filter.list_must_be_one_placeholder', 'condition' => 'nodes.filter.condition_must_be_one_placeholder'] as $name => $problem) {
            $value = trim((string) ($config[$name] ?? ''));

            if ($value !== '' && ! Conditional::isOnePlaceholder($value)) {
                $problems[] = __($problem);
            }
        }

        return $problems;
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('items', __('nodes.filter.items_that_match'), 'array'),
            new Output('count', __('nodes.filter.how_many_match'), 'number'),
            new Output('removed', __('nodes.filter.items_that_do_not_match'), 'array'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $items = $context->input('items');

        if (! is_array($items) || ! array_is_list($items)) {
            return NodeResult::fail(__('nodes.filter.list_is_not_a_list', ['type' => get_debug_type($items)]), retryable: false);
        }

        $condition = (string) $context->config('condition', '');
        $kept = [];
        $removed = [];

        foreach ($items as $index => $item) {
            try {
                $matches = Conditional::isTrue($context->render($condition, ['item' => $item, 'index' => $index]));
            } catch (TemplateError $error) {
                return NodeResult::fail(__('nodes.filter.item_index_message', ['index' => $index, 'message' => $error->getMessage()]), retryable: false);
            }

            if ($matches) {
                $kept[] = $item;
            } else {
                $removed[] = $item;
            }
        }

        return NodeResult::complete(['items' => $kept, 'count' => count($kept), 'removed' => $removed]);
    }
}
