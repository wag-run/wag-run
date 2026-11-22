<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Follows the port named by its "take" config: "true" or "false".
 */
class Route extends Node
{
    public static function type(): string
    {
        return 'test.route';
    }

    public function label(): string
    {
        return 'Route';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function ports(array $config = []): array
    {
        return ['true', 'false'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete(['took' => $context->config('take')], $context->config('take', 'true'));
    }
}
