<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Resumption;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;

/**
 * Waits for an outside response, failing after "timeout_hours".
 */
class Ask extends Node
{
    public static function type(): string
    {
        return 'test.ask';
    }

    public function label(): string
    {
        return 'Ask';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::waitForResponse(now()->addHours((int) $context->config('timeout_hours', 48)));
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        return $resumption->reason === ResumeReason::TimerElapsed
            ? NodeResult::fail('No response before the timeout.', retryable: false)
            : NodeResult::complete(['answer' => $resumption->payload['answer'] ?? null]);
    }
}
