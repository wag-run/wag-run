<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Builds a value from earlier outputs and workspace values and returns it
 * for later nodes to use: text with {{ }} placeholders, or a single
 * placeholder, which keeps its type ({{ item.price * item.quantity }} is a
 * number, {{ nodes.fetch.output.body.items }} a list).
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
            new Field('template', __('nodes.compose.value'), Field::TEXTAREA, templated: true, description: __('nodes.compose.text_with_placeholders_or_one')),
            new Field('parse_json', __('nodes.compose.parse_the_result_as_json'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('value', __('common.value'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $value = $context->input('template', '');

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
