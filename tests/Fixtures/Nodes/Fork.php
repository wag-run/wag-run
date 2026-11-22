<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Continues down every branch port at once.
 */
class Fork extends Node
{
    public static function type(): string
    {
        return 'test.fork';
    }

    public function label(): string
    {
        return 'Fork';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function ports(array $config = []): array
    {
        return $config['branches'] ?? ['a', 'b'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete(null, $this->ports($context->config()));
    }
}
