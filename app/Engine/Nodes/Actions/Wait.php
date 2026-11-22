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
        return __('Wait');
    }

    public function description(): string
    {
        return __('Pauses the flow for a set time.');
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
        return 'clock';
    }

    public function inputs(): array
    {
        return [
            new Field('mode', __('Wait'), Field::SELECT, required: true, default: 'duration', options: ['duration' => __('For a duration'), 'until' => __('Until a time')]),
            new Field('amount', __('Amount'), Field::NUMBER, default: 5, templated: true),
            new Field('unit', __('Unit'), Field::SELECT, default: 'minutes', options: ['seconds' => __('Seconds'), 'minutes' => __('Minutes'), 'hours' => __('Hours'), 'days' => __('Days')]),
            new Field('until', __('Until'), Field::TEXT, templated: true, description: __('A date and time, such as 2026-12-01 09:00 or {{ trigger.send_at }}')),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('waited_until', __('Waited until'), 'string')];
    }

    public function execute(NodeContext $context): NodeResult
    {
        try {
            $until = $context->input('mode') === 'until'
                ? CarbonImmutable::parse((string) $context->input('until'))
                : now()->add((string) $context->input('unit', 'minutes'), max(0, (float) $context->input('amount', 0)));
        } catch (Throwable) {
            return NodeResult::fail(__('Could not read the time to wait until.'), retryable: false);
        }

        $output = ['waited_until' => $until->toIso8601String()];

        return $until->isFuture()
            ? NodeResult::waitUntil($until, ['output' => $output])
            : NodeResult::complete($output);
    }
}
