<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Routes the flow down "true" or "false" by evaluating an ExpressionLanguage
 * condition. Only one path runs.
 */
class Conditional extends Node
{
    public static function type(): string
    {
        return 'core.conditional';
    }

    public function label(): string
    {
        return __('Conditional logic');
    }

    public function description(): string
    {
        return __('Routes the flow based on a condition.');
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
        return 'arrows-right-left';
    }

    public function inputs(): array
    {
        return [
            new Field('condition', __('Condition'), Field::TEXT, required: true, expression: true,
                description: __('An expression, such as nodes.fetch.output.status == 200 and trigger.amount > 100')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('result', __('Result'), 'boolean')];
    }

    public function ports(array $config = []): array
    {
        return ['true', 'false'];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $result = (bool) $context->input('condition');

        return NodeResult::complete(['result' => $result], $result ? 'true' : 'false');
    }
}
