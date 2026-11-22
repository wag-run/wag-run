<?php

namespace App\Engine\Nodes\Actions;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Pauses the flow for a duration or until a time. No worker is held while
 * it waits; the scheduled sweep resumes it.
 */
class Wait extends Node
{
    public static function type(): string
    {
        return 'core.wait';
    }

    public function label(): string
    {
        return __('nodes.wait.wait');
    }

    public function description(): string
    {
        return __('nodes.wait.pauses_the_flow_for_a');
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
        return 'clock';
    }

    public function inputs(): array
    {
        return [
            new Field('mode', __('nodes.wait.wait'), Field::SELECT, required: true, default: 'duration', options: ['duration' => __('nodes.wait.for_a_duration'), 'until' => __('nodes.wait.until_a_time')]),
            new Field('amount', __('nodes.wait.amount'), Field::NUMBER, default: 5, templated: true, visibleWhen: ['mode' => 'duration']),
            new Field('unit', __('nodes.wait.unit'), Field::SELECT, default: 'minutes', options: ['seconds' => __('nodes.wait.seconds'), 'minutes' => __('nodes.wait.minutes'), 'hours' => __('nodes.wait.hours'), 'days' => __('nodes.wait.days')], visibleWhen: ['mode' => 'duration']),
            new Field('until', __('nodes.wait.until'), Field::TEXT, required: true, templated: true, description: __('nodes.wait.date_and_time_such_as'), visibleWhen: ['mode' => 'until']),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('waited_until', __('nodes.wait.waited_until'), 'string')];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $until = $context->input('mode') === 'until'
                ? CarbonImmutable::parse((string) $context->input('until'))
                : now()->add((string) $context->input('unit', 'minutes'), max(0, (float) $context->input('amount', 0)));
        } catch (Throwable) {
            return NodeResult::fail(__('nodes.wait.could_not_read_the_time'), retryable: false);
        }

        $output = ['waited_until' => $until->toIso8601String()];

        return $until->isFuture()
            ? NodeResult::waitUntil($until, ['output' => $output])
            : NodeResult::complete($output);
    }
}
