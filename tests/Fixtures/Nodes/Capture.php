<?php

namespace Tests\Fixtures\Nodes;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Resumption;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;

/**
 * Records the value it was given, as the node received it, and outputs it
 * as "token". With wait_minutes it first waits, keeping the value in its
 * state, and records and outputs what it reads back from there. With
 * fail it fails with a message that holds the value.
 */
class Capture extends Node
{
    /**
     * What each node received, by node id, in order.
     *
     * @var array<string, list<mixed>>
     */
    public static array $seen = [];

    public static function type(): string
    {
        return 'test.capture';
    }

    public function label(): string
    {
        return 'Capture';
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function inputs(): array
    {
        return [new Field('value', 'Value', templated: true)];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $value = $context->input('value');

        if ($context->config('wait_minutes')) {
            return NodeResult::waitUntil(now()->addMinutes((int) $context->config('wait_minutes')), ['kept' => ['token' => $value]]);
        }

        self::$seen[$context->step->node_id][] = $value;

        if ($context->config('fail')) {
            return NodeResult::fail("Rejected {$value}", retryable: false);
        }

        return NodeResult::complete(['token' => $value]);
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        if ($resumption->reason !== ResumeReason::TimerElapsed) {
            return parent::resume($context, $resumption);
        }

        $value = $context->state()['kept']['token'] ?? null;
        self::$seen[$context->step->node_id][] = $value;

        return NodeResult::complete(['token' => $value]);
    }
}
