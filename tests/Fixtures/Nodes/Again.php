<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Runs its "pass" branch with NodeResult::repeat() and leaves what happens
 * after a pass to the default resume().
 */
class Again extends Node
{
    public static function type(): string
    {
        return 'test.again';
    }

    public function label(): string
    {
        return 'Again';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function ports(array $config = []): array
    {
        return ['pass', 'done'];
    }

    public function repeats(): bool
    {
        return true;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::repeat();
    }
}
