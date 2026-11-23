<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Routes the flow down "true" or "false". The condition is one {{ }}
 * placeholder, such as {{ trigger.amount > 100 }}; publishing refuses
 * anything else, since plain text would always count as true. Only one
 * path runs.
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
            new Field('condition', __('nodes.conditional.condition'), Field::TEXT, required: true, templated: true,
                description: __('nodes.conditional.one_placeholder_such_as')),
        ];
    }

    public function configProblems(array $config): array
    {
        $condition = trim((string) ($config['condition'] ?? ''));

        return $condition === '' || static::isOnePlaceholder($condition) ? [] : [__('nodes.conditional.condition_must_be_one_placeholder')];
    }

    public static function isOnePlaceholder(string $text): bool
    {
        return str_starts_with($text, '{{') && str_ends_with($text, '}}') && substr_count($text, '{{') === 1;
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
        $value = $context->input('condition');
        $result = is_string($value)
            ? (filter_var(trim($value), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? trim($value) !== '')
            : (bool) $value;

        return NodeResult::complete(['result' => $result], $result ? 'true' : 'false');
    }
}
