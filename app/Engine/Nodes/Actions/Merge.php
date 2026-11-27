<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Waits for every parallel branch that can still reach it, then continues
 * once with the last node of each branch, in the same shape as nodes.<id>.
 */
class Merge extends Node
{
    public static function type(): string
    {
        return 'core.merge';
    }

    public function label(): string
    {
        return __('nodes.merge.merge');
    }

    public function description(): string
    {
        return __('nodes.merge.waits_for_all_parallel_branches');
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
        return 'arrows-pointing-in';
    }

    public function hasErrorPort(): bool
    {
        return false;
    }

    public function joinsBranches(): bool
    {
        return true;
    }

    public function outputs(array $config = []): array
    {
        return [new Output('branches', __('nodes.merge.branch_outputs'), 'object', __('nodes.merge.last_node_on_each_branch'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $nodes = $context->data()['nodes'];
        $branches = [];

        foreach ($context->state()['arrivals'] ?? [] as $arrival) {
            $branches[$arrival['node_id']] = $nodes[$arrival['node_id']] ?? null;
        }

        return NodeResult::complete(['branches' => $branches]);
    }
}
