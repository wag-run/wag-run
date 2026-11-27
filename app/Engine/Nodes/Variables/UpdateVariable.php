<?php

namespace App\Engine\Nodes\Variables;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Enums\ValueOperation;
use App\Enums\ValueType;

/**
 * Changes one of the workflow's variables for the rest of the run: Set
 * replaces its value, Append adds to a String or an Array, and Increment
 * adds to a Number. Reading is by placeholder, {{ vars.<name> }}.
 *
 * The node only says what to change. The engine makes the change while it
 * holds the run's lock (see Coordinator::updateVariable), so two parallel
 * branches can't lose each other's Append or Increment.
 */
class UpdateVariable extends Node
{
    public static function type(): string
    {
        return 'core.variable_update';
    }

    public function label(): string
    {
        return __('nodes.variables.update_variable');
    }

    public function description(): string
    {
        return __('nodes.variables.update_variable_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.compose.data');
    }

    public function icon(): string
    {
        return 'variable';
    }

    public function inputs(): array
    {
        return [
            new Field('variable', __('nodes.variables.variable'), Field::VARIABLE, required: true, description: __('nodes.variables.variable_help')),
            new Field('operation', __('nodes.store.operation'), Field::SELECT, required: true, default: ValueOperation::Set->value,
                options: collect(ValueOperation::cases())->mapWithKeys(fn (ValueOperation $operation) => [$operation->value => $operation->label()])->all(),
                optionsWhen: [
                    ValueOperation::Append->value => ['variable.type' => [ValueType::String->value, ValueType::Array->value]],
                    ValueOperation::Increment->value => ['variable.type' => ValueType::Number->value],
                ]),
            new Field('value', __('common.value'), Field::TEXTAREA, templated: true, description: __('nodes.store.value_help'), visibleWhen: ['operation' => [ValueOperation::Set->value, ValueOperation::Append->value]]),
            new Field('amount', __('nodes.store.amount'), Field::NUMBER, default: 1, templated: true, description: __('nodes.variables.amount_help'), visibleWhen: ['operation' => ValueOperation::Increment->value]),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('variable', __('nodes.variables.variable'), 'string'),
            new Output('operation', __('nodes.store.operation'), 'string'),
            new Output('before', __('nodes.store.value_before'), 'any', __('nodes.variables.value_before_help')),
            new Output('after', __('nodes.store.value_after'), 'any', __('nodes.variables.value_after_help')),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $operation = ValueOperation::tryFrom((string) $context->input('operation', ValueOperation::Set->value)) ?? ValueOperation::Set;

        return NodeResult::updateVariable(
            (string) $context->input('variable'),
            $operation,
            $operation === ValueOperation::Increment ? $context->input('amount', 1) : $context->input('value'),
        );
    }
}
