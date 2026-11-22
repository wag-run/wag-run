<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Models\Workflow;

class CallWorkflow extends Node
{
    public static function type(): string
    {
        return 'test.call';
    }

    public function label(): string
    {
        return 'Call workflow';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::runChild(Workflow::findOrFail($context->config('workflow_id')), ['from' => 'parent']);
    }
}
