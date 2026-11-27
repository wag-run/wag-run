<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Engine\Resumption;
use App\Enums\NodeKind;
use App\Enums\ResumeReason;

/**
 * Repeats the steps connected to "each pass" while a condition is true, one
 * pass at a time, then continues down "done" with every pass's results,
 * their count, and the last pass on its own, the same outputs as a For
 * loop. The results are read from the step rows (LoopResults).
 *
 * The condition is one {{ }} placeholder and always means "keep going while
 * this is true". It is checked before each pass, or after each pass when
 * "check condition after pass" is on, so the loop then runs at least once.
 * A check that follows a pass reads the run's data as a step inside that
 * pass does, so nodes.<id> is that pass's node; the check before the first
 * pass sees no pass at all, and loop.index and loop.previous are null. A loop whose condition is still true after its
 * maximum number of passes fails.
 *
 * Inside a pass, templates can read loop.index, loop.previous (the pass
 * before, each node as in nodes.<id>), and loop.results (every earlier pass).
 */
class WhileLoop extends Node
{
    public const DEFAULT_MAX_PASSES = 100;

    public const MAX_PASSES = 10000;

    public static function type(): string
    {
        return 'core.while_loop';
    }

    public function label(): string
    {
        return __('nodes.while_loop.while_loop');
    }

    public function labelFor(array $config): string
    {
        return static::checksAfterPass($config) ? __('nodes.while_loop.do_while_loop') : $this->label();
    }

    public function description(): string
    {
        return __('nodes.while_loop.repeats_part_of_the_flow');
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
        return 'arrow-path-rounded-square';
    }

    public function inputs(): array
    {
        return [
            new Field('condition', __('nodes.conditional.condition'), Field::TEXT, required: true, templated: true,
                description: __('nodes.while_loop.checked_before_each_pass')),
            new Field('check_after', __('nodes.while_loop.check_condition_after_pass'), Field::BOOLEAN, default: false),
            new Field('max_passes', __('nodes.while_loop.maximum_passes'), Field::NUMBER, required: true, default: self::DEFAULT_MAX_PASSES,
                description: __('nodes.while_loop.the_loop_fails_if', ['max' => number_format(self::MAX_PASSES)])),
        ];
    }

    public function fieldHelp(Field $field, array $config): ?string
    {
        if ($field->name === 'condition' && static::checksAfterPass($config)) {
            return __('nodes.while_loop.checked_after_each_pass');
        }

        return $field->description;
    }

    public function configProblems(array $config): array
    {
        $problems = [];
        $condition = trim((string) ($config['condition'] ?? ''));

        if ($condition !== '' && ! Conditional::isOnePlaceholder($condition)) {
            $problems[] = __('nodes.while_loop.condition_must_be_one_placeholder');
        }

        if (static::maxPasses($config) === null) {
            $problems[] = __('nodes.while_loop.maximum_passes_must_be_between', ['max' => number_format(self::MAX_PASSES)]);
        }

        return $problems;
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('results', __('nodes.for_loop.results'), 'array', __('nodes.while_loop.what_every_node_produced_in')),
            new Output('count', __('nodes.while_loop.passes_run'), 'number'),
            new Output('last', __('nodes.while_loop.last_pass'), 'object', __('nodes.while_loop.what_every_node_produced_in_the_last')),
        ];
    }

    public function ports(array $config = []): array
    {
        return ['pass', 'done'];
    }

    public function portLabel(string $port, array $config = []): ?string
    {
        if ($port === 'pass') {
            return __('nodes.while_loop.each_pass');
        }

        return null;
    }

    public function repeats(): bool
    {
        return true;
    }

    public function execute(NodeContext $context): NodeResult
    {
        if (static::maxPasses($context->config()) === null) {
            return NodeResult::fail(__('nodes.while_loop.maximum_passes_must_be_between', ['max' => number_format(self::MAX_PASSES)]), retryable: false);
        }

        if (! static::checksAfterPass($context->config()) && ! Conditional::isTrue($context->input('condition'))) {
            return NodeResult::complete(['results' => [], 'count' => 0, 'last' => null], 'done');
        }

        return NodeResult::repeat();
    }

    public function resume(NodeContext $context, Resumption $resumption): NodeResult
    {
        if ($resumption->reason !== ResumeReason::PassFinished) {
            return parent::resume($context, $resumption);
        }

        $passes = (int) ($context->state()['index'] ?? 0) + 1;

        if (! Conditional::isTrue($context->input('condition'))) {
            return NodeResult::complete(['count' => $passes], 'done');
        }

        $max = static::maxPasses($context->config()) ?? self::DEFAULT_MAX_PASSES;

        if ($passes >= $max) {
            return NodeResult::fail(trans_choice('nodes.while_loop.condition_still_true_after', $max, ['max' => number_format($max)]), retryable: false, output: ['count' => $passes]);
        }

        return NodeResult::repeat();
    }

    /**
     * Whether the condition is first checked after the first pass, not
     * before it.
     *
     * @param  array<string, mixed>  $config
     */
    public static function checksAfterPass(array $config): bool
    {
        return filter_var($config['check_after'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * The most passes the loop may run, or null when the setting is not a
     * whole number from 1 to MAX_PASSES. Not set means the default.
     *
     * @param  array<string, mixed>  $config
     */
    public static function maxPasses(array $config): ?int
    {
        $value = $config['max_passes'] ?? null;

        if ($value === null || $value === '') {
            return self::DEFAULT_MAX_PASSES;
        }

        $max = filter_var($value, FILTER_VALIDATE_INT);

        return $max !== false && $max >= 1 && $max <= self::MAX_PASSES ? $max : null;
    }
}
