<?php

namespace App\Engine\Nodes\Triggers;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Support\CronDescription;
use App\Support\TimeZones;
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
        return __('nodes.schedule_trigger.schedule');
    }

    public function description(): string
    {
        return __('nodes.schedule_trigger.configured_time_or_interval_is');
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
            new Field('mode', __('common.run'), Field::SELECT, required: true, default: 'interval', options: ['interval' => __('nodes.schedule_trigger.every_n_minutes'), 'cron' => __('nodes.schedule_trigger.on_a_cron_schedule')]),
            new Field('interval_minutes', __('nodes.schedule_trigger.every_minutes'), Field::NUMBER, default: 60, visibleWhen: ['mode' => 'interval']),
            new Field('cron', __('nodes.schedule_trigger.cron_expression'), Field::CRON, required: true, description: __('nodes.schedule_trigger.such_as_0_9_1'), visibleWhen: ['mode' => 'cron']),
            new Field('timezone', __('nodes.schedule_trigger.time_zone'), Field::TIMEZONE, default: 'UTC', visibleWhen: ['mode' => 'cron']),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [new Output('scheduled_at', __('nodes.schedule_trigger.scheduled_time'), 'string')];
    }

    /**
     * A cron schedule that is not a valid expression, or whose time zone is
     * unknown, would never run.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function configProblems(array $config): array
    {
        if (($config['mode'] ?? 'interval') !== 'cron') {
            return [];
        }

        $problems = [];
        $cron = (string) ($config['cron'] ?? '');
        $timezone = trim((string) ($config['timezone'] ?? '')) ?: 'UTC';

        if (trim($cron) !== '' && ! CronDescription::isValid($cron)) {
            $problems[] = __('nodes.cron.not_a_valid_cron_expression');
        }

        if (! TimeZones::isKnown($timezone)) {
            $problems[] = __('nodes.cron.unknown_time_zone', ['timezone' => $timezone]);
        }

        return $problems;
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
