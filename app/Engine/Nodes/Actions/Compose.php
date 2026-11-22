<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Builds a value from earlier outputs and workspace values with a Twig
 * template or an expression, and returns it for later nodes to use.
 */
class Compose extends Node
{
    public static function type(): string
    {
        return 'core.compose';
    }

    public function label(): string
    {
        return __('Compose');
    }

    public function description(): string
    {
        return __('Builds a value with a template or expression and returns it as the output.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('Data');
    }

    public function icon(): string
    {
        return 'puzzle-piece';
    }

    public function inputs(): array
    {
        return [
            new Field('mode', __('Build with'), Field::SELECT, required: true, default: 'template', options: ['template' => __('Template (Twig)'), 'expression' => __('Expression')]),
            new Field('template', __('Template'), Field::TEXTAREA, description: __('Twig, such as Order {{ trigger.id }} for {{ trigger.customer.name|title }}')),
            new Field('expression', __('Expression'), Field::TEXT, description: __('Such as item.price * item.quantity')),
            new Field('parse_json', __('Parse the result as JSON'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('value', __('Value'))];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $value = $context->config('mode') === 'expression'
            ? $context->evaluate((string) $context->config('expression'))
            : $context->render((string) $context->config('template', ''));

        if ($context->config('parse_json') && is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return NodeResult::fail(__('The result is not valid JSON: :error', ['error' => json_last_error_msg()]), retryable: false);
            }

            $value = $decoded;
        }

        return NodeResult::complete(['value' => $value]);
    }
}
