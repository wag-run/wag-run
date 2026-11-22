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
        return __('nodes.conditional.conditional_logic');
    }

    public function description(): string
    {
        return __('nodes.conditional.routes_the_flow_based_on');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('common.flow');
    }

    public function icon(): string
    {
        return 'arrows-right-left';
    }

    public function inputs(): array
    {
        return [
            new Field('condition', __('nodes.conditional.condition'), Field::TEXT, required: true, expression: true,
                description: __('nodes.conditional.expression_such_as_nodes_fetch')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('result', __('nodes.conditional.result'), 'boolean')];
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
