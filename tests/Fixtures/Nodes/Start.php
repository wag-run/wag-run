<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

class Start extends Node
{
    public static function type(): string
    {
        return 'test.start';
    }

    public function label(): string
    {
        return 'Start';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }
}
