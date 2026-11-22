<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;

/**
 * Ends the whole execution with a chosen status. Every other open step is
 * cancelled. Ending as failed cannot be caught by an error output.
 */
class Terminate extends Node
{
    public static function type(): string
    {
        return 'core.terminate';
    }

    public function label(): string
    {
        return __('Terminate');
    }

    public function description(): string
    {
        return __('Ends the execution here with a chosen status.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Flow');
    }

    public function icon(): string
    {
        return 'stop-circle';
    }

    public function hasErrorPort(): bool
    {
        return false;
    }

    public function ports(array $config = []): array
    {
        return [];
    }

    public function inputs(): array
    {
        return [
            new Field('status', __('End as'), Field::SELECT, required: true, default: 'complete', options: [
                'complete' => __('Complete'),
                'failed' => __('Failed'),
                'cancelled' => __('Cancelled'),
            ]),
            new Field('output', __('Output'), Field::TEXTAREA, templated: true, description: __('Optional. Becomes the execution\'s output.')),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $status = ExecutionStatus::tryFrom((string) $context->input('status')) ?? ExecutionStatus::Complete;

        if (! in_array($status, [ExecutionStatus::Complete, ExecutionStatus::Failed, ExecutionStatus::Cancelled], true)) {
            $status = ExecutionStatus::Complete;
        }

        return NodeResult::terminate($status, $context->input('output'));
    }
}
