<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;

class Stop extends Node
{
    public static function type(): string
    {
        return 'test.stop';
    }

    public function label(): string
    {
        return 'Stop';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::terminate(ExecutionStatus::from($context->config('status', 'complete')), ['stopped' => true]);
    }
}
