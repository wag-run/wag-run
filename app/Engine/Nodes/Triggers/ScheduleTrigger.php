<?php

namespace App\Engine\Nodes\Triggers;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Throwable;

/**
 * Starts the workflow on a cron schedule or at a fixed interval.
 */
class ScheduleTrigger extends Node
{
    public static function type(): string
    {
        return 'core.schedule_trigger';
    }

    public function label(): string
    {
        return __('Schedule');
    }

    public function description(): string
    {
        return __('A configured time or interval is reached.');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Trigger;
    }

    public function icon(): string
    {
        return 'calendar-days';
    }

    public function inputs(): array
    {
        return [
            new Field('mode', __('Run'), Field::SELECT, required: true, default: 'interval', options: ['interval' => __('Every N minutes'), 'cron' => __('On a cron schedule')]),
            new Field('interval_minutes', __('Every (minutes)'), Field::NUMBER, default: 60),
            new Field('cron', __('Cron expression'), Field::TEXT, description: __('Such as 0 9 * * 1-5 for 9:00 on weekdays.')),
            new Field('timezone', __('Time zone'), Field::TEXT, default: 'UTC'),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('scheduled_at', __('Scheduled time'), 'string')];
    }

    public function execute(NodeContext $context): NodeResult
    {
        return NodeResult::complete($context->triggerInput());
    }

    /**
     * When the schedule next fires after $after, or null if it is not valid.
     *
     * @param  array<string, mixed>  $config
     */
    public static function nextRunAfter(array $config, CarbonInterface $after): ?CarbonImmutable
    {
        try {
            if (($config['mode'] ?? 'interval') === 'cron') {
                $timezone = (string) ($config['timezone'] ?? 'UTC') ?: 'UTC';
                $next = (new CronExpression((string) ($config['cron'] ?? '')))->getNextRunDate($after->toDateTimeImmutable(), 0, false, $timezone);

                return CarbonImmutable::instance($next)->utc();
            }

            $minutes = max(1, (int) ($config['interval_minutes'] ?? 60));

            return CarbonImmutable::instance($after->toDateTimeImmutable())->addMinutes($minutes)->startOfMinute();
        } catch (Throwable) {
            return null;
        }
    }
}
