<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Runs its "item" branch once per entry of "items" (or of the current loop
 * item, when "items_from_item" is set), then continues down "done".
 */
class Each extends Node
{
    public static function type(): string
    {
        return 'test.each';
    }

    public function label(): string
    {
        return 'Each';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function ports(array $config = []): array
    {
        return ['item', 'done'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $items = $context->config('items_from_item') ? ($context->data()['item'] ?? []) : $context->config('items', []);

        return NodeResult::iterate($items);
    }
}
