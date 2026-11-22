<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\RetryPolicy;
use App\Enums\NodeKind;
use RuntimeException;

/**
 * Fails its first "fail_times" attempts, then succeeds. With "throw" set it
 * throws instead of returning a failure; with "permanent" set the failure is
 * not retryable.
 */
class Flaky extends Node
{
    public static function type(): string
    {
        return 'test.flaky';
    }

    public function label(): string
    {
        return 'Flaky';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function defaultRetryPolicy(): RetryPolicy
    {
        return new RetryPolicy(maxRetries: 0, backoffSeconds: 10, multiplier: 2);
    }

    public function execute(NodeContext $context): NodeResult
    {
        if ($context->step->attempt <= (int) $context->config('fail_times', PHP_INT_MAX)) {
            if ($context->config('throw')) {
                throw new RuntimeException("Exploded on attempt {$context->step->attempt}");
            }

            return NodeResult::fail("Failed attempt {$context->step->attempt}", retryable: ! $context->config('permanent'));
        }

        return NodeResult::complete(['attempt' => $context->step->attempt]);
    }
}
