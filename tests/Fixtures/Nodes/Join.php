<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

class Join extends Node
{
    public static function type(): string
    {
        return 'test.join';
    }

    public function label(): string
    {
        return 'Join';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function joinsBranches(): bool
    {
        return true;
    }

    public function execute(NodeContext $context): NodeResult
    {
        $arrived = array_column($context->state()['arrivals'] ?? [], 'node_id');
        sort($arrived);

        return NodeResult::complete(['arrived' => $arrived]);
    }
}
