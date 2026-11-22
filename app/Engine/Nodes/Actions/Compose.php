<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Builds a value from earlier outputs and workspace values with text that
 * has {{ }} placeholders, or with one expression, and returns it for later
 * nodes to use.
 */
class Compose extends Node
{
    public static function type(): string
    {
        return 'core.compose';
    }

    public function label(): string
    {
        return __('nodes.compose.compose');
    }

    public function description(): string
    {
        return __('nodes.compose.builds_a_value_with_a');
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
        return 'puzzle-piece';
    }

    public function inputs(): array
    {
        return [
            new Field('mode', __('nodes.compose.build_with'), Field::SELECT, required: true, default: 'template', options: ['template' => __('nodes.compose.text_with_placeholders'), 'expression' => __('nodes.compose.expression')]),
            new Field('template', __('nodes.compose.template'), Field::TEXTAREA, description: __('nodes.compose.text_such_as_order_trigger'), visibleWhen: ['mode' => 'template']),
            new Field('expression', __('nodes.compose.expression'), Field::TEXT, description: __('nodes.compose.such_as_item_price_item'), visibleWhen: ['mode' => 'expression']),
            new Field('parse_json', __('nodes.compose.parse_the_result_as_json'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('value', __('common.value'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $value = $context->config('mode') === 'expression'
            ? $context->evaluate((string) $context->config('expression'))
            : $context->render((string) $context->config('template', ''));

        if ($context->config('parse_json') && is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return NodeResult::fail(__('nodes.compose.result_is_not_valid_json', ['error' => json_last_error_msg()]), retryable: false);
            }

            $value = $decoded;
        }

        return NodeResult::complete(['value' => $value]);
    }
}
