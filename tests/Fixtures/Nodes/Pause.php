<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Waits for "minutes" minutes, then continues.
 */
class Pause extends Node
{
    public static function type(): string
    {
        return 'test.pause';
    }

    public function label(): string
    {
        return 'Pause';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::waitUntil(now()->addMinutes((int) $context->config('minutes', 5)), ['output' => ['waited' => true]]);
    }
}
