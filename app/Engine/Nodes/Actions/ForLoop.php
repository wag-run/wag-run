<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Runs the steps connected to "each item" once per item, one item at a time,
 * then continues down "done" with every iteration's results. Inside the
 * loop, templates can read item, loop.index and loop.count.
 */
class ForLoop extends Node
{
    public const MAX_ITEMS = 10000;

    public static function type(): string
    {
        return 'core.for_loop';
    }

    public function label(): string
    {
        return __('For loop');
    }

    public function description(): string
    {
        return __('Repeats part of the flow for each item.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Flow');
    }

    public function icon(): string
    {
        return 'arrow-path';
    }

    public function inputs(): array
    {
        return [
            new Field('items', __('Items'), Field::TEXT, required: true, templated: true,
                description: __('A list to loop over, such as {{ nodes.fetch.output.body.items }}')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('results', __('Results'), 'array', __('What each iteration produced, keyed by the node that ended it.'))];
    }

    public function ports(array $config = []): array
    {
        return ['item', 'done'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $items = $context->input('items');

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = is_array($decoded) ? $decoded : ($items === '' ? [] : null);
        }

        if (! is_array($items)) {
            return NodeResult::fail(__('Items must be a list.'), retryable: false);
        }

        if (count($items) > self::MAX_ITEMS) {
            return NodeResult::fail(__('A loop can run at most :max items.', ['max' => self::MAX_ITEMS]), retryable: false);
        }

        return NodeResult::iterate(array_values($items));
    }
}
