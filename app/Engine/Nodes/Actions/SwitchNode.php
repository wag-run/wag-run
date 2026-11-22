<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;

/**
 * Compares one value with a list of cases and continues down the output of
 * the first case it equals, or "default" when none does.
 */
class SwitchNode extends Node
{
    public const MAX_CASES = 20;

    public static function type(): string
    {
        return 'core.switch';
    }

    public function label(): string
    {
        return __('nodes.switch.switch');
    }

    public function description(): string
    {
        return __('nodes.switch.routes_the_flow_by_matching');
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
        return 'queue-list';
    }

    public function inputs(): array
    {
        return [
            new Field('value', __('nodes.switch.value'), Field::TEXT, required: true, templated: true, description: __('nodes.switch.the_value_to_match_such')),
            new Field('cases', __('nodes.switch.cases'), Field::LIST, required: true, default: [['value' => '']], description: __('nodes.switch.each_case_gets_its_own'), fields: [
                new Field('value', __('nodes.switch.matches'), Field::TEXT),
            ]),
            new Field('ignore_case', __('nodes.switch.ignore_upper_and_lower_case'), Field::BOOLEAN, default: false),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('value', __('nodes.switch.value'), 'string', __('nodes.switch.the_value_that_was_matched')),
            new Output('matched', __('nodes.switch.matched_output'), 'string', __('nodes.switch.the_output_taken')),
            new Output('case', __('nodes.switch.matched_case'), 'string', __('nodes.switch.the_case_it_equalled')),
        ];
    }

    public function ports(array $config = []): array
    {
        $count = min(self::MAX_CASES, count(array_filter((array) ($config['cases'] ?? []), 'is_array')));

        return [...array_map(fn (int $i) => "case_{$i}", $count > 0 ? range(1, $count) : []), 'default'];
    }

    public function portLabel(string $port, array $config = []): ?string
    {
        if ($port === 'default') {
            return __('nodes.switch.default');
        }

        if (! str_starts_with($port, 'case_')) {
            return null;
        }

        $cases = array_values(array_filter((array) ($config['cases'] ?? []), 'is_array'));
        $value = trim((string) ($cases[(int) substr($port, 5) - 1]['value'] ?? ''));

        return $value === '' ? __('nodes.switch.case_n', ['n' => substr($port, 5)]) : '= '.$value;
    }

    public function portsAfterRowRemoved(string $field, int $index, array $config = []): array
    {
        if ($field !== 'cases') {
            return [];
        }

        $count = count($this->ports($config)) - 1;
        $moves = ['case_'.($index + 1) => null];

        for ($i = $index + 2; $i <= $count; $i++) {
            $moves["case_{$i}"] = 'case_'.($i - 1);
        }

        return $moves;
    }

    public function execute(NodeContext $context): NodeResult
    {
        $value = self::stringify($context->input('value'));
        $ignoreCase = (bool) $context->input('ignore_case');
        $cases = array_slice(array_values(array_filter((array) $context->config('cases', []), 'is_array')), 0, self::MAX_CASES);

        foreach ($cases as $index => $case) {
            $candidate = trim((string) ($case['value'] ?? ''));

            if ($ignoreCase ? mb_strtolower($candidate) === mb_strtolower($value) : $candidate === $value) {
                $port = 'case_'.($index + 1);

                return NodeResult::complete(['value' => $value, 'matched' => $port, 'case' => $candidate], $port);
            }
        }

        return NodeResult::complete(['value' => $value, 'matched' => 'default', 'case' => null], 'default');
    }

    protected static function stringify(mixed $value): string
    {
        return trim(match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) || $value === null => (string) $value,
            default => (string) json_encode($value),
        });
    }
}
