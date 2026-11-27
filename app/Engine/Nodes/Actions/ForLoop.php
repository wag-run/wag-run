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
 * then continues down "done" with every iteration's results, their count,
 * and the last iteration on its own.
 * The results are read from the step rows (LoopResults). Inside the loop,
 * templates can read item, loop.index and loop.count.
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
        return __('nodes.for_loop.for_loop');
    }

    public function description(): string
    {
        return __('nodes.for_loop.repeats_part_of_the_flow');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.flow');
    }

    public function icon(): string
    {
        return 'arrow-path';
    }

    public function inputs(): array
    {
        return [
            new Field('items', __('nodes.for_loop.items'), Field::TEXT, required: true, templated: true,
                description: __('nodes.for_loop.list_to_loop_over_such')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('results', __('nodes.for_loop.results'), 'array', __('nodes.for_loop.what_every_node_produced_in')),
            new Output('count', __('nodes.for_loop.iterations_run'), 'number'),
            new Output('last', __('nodes.for_loop.last_iteration'), 'object', __('nodes.for_loop.what_every_node_produced_in_the_last')),
        ];
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
            return NodeResult::fail(__('nodes.for_loop.items_must_be_a_list'), retryable: false);
        }

        if (count($items) > self::MAX_ITEMS) {
            return NodeResult::fail(__('nodes.for_loop.loop_can_run_at_most', ['max' => self::MAX_ITEMS]), retryable: false);
        }

        return NodeResult::iterate(array_values($items));
    }
}
