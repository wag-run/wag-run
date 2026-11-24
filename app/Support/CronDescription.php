<?php

namespace App\Support;

use App\Engine\Nodes\Triggers\ScheduleTrigger;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Str;

/**
 * Puts a five-part cron expression into words, such as "At 09:00 on
 * weekdays", and lists when it next runs. Expressions it cannot put into
 * words (both a day of the month and a day of the week, steps in the day
 * fields, L, W or #) get no description, only their next runs.
 */
final class CronDescription
{
    /**
     * The shorthands cron accepts, as the five parts they stand for.
     *
     * @var array<string, string>
     */
    private const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const DAY_NAMES = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    private const MONTH_NAMES = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function isValid(string $expression): bool
    {
        return trim($expression) !== '' && CronExpression::isValidExpression(trim($expression));
    }

    /**
     * The expression in words, or null when it is not valid or is not one
     * this can describe.
     */
    public static function describe(string $expression): ?string
    {
        if (! self::isValid($expression)) {
            return null;
        }

        $expression = strtolower(trim($expression));
        $parts = preg_split('/\s+/', self::MACROS[$expression] ?? $expression) ?: [];

        if (count($parts) !== 5) {
            return null;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;
        $dayOfWeek = strtr($dayOfWeek, self::DAY_NAMES);
        $month = strtr($month, self::MONTH_NAMES);

        $time = self::timePhrase($minute, $hour);
        $days = self::dayPhrase($dayOfMonth, $dayOfWeek, $month);

        if ($time === null || $days === false) {
            return null;
        }

        [$phrase, $fixedTime] = $time;
        [$days, $months] = $days;

        if ($days === null && $fixedTime) {
            $phrase = __('nodes.cron.every_day').' '.$phrase;
        }

        return Str::ucfirst(implode(' ', array_filter([$phrase, $days, $months])));
    }

    /**
     * When the schedule next runs, in $timezone. Empty when the expression
     * or the time zone is not valid.
     *
     * @return list<CarbonImmutable>
     */
    public static function nextRuns(string $expression, string $timezone = 'UTC', int $count = 3, ?CarbonImmutable $after = null): array
    {
        $timezone = trim($timezone) ?: 'UTC';

        if (! self::isValid($expression) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return [];
        }

        $runs = [];
        $after ??= CarbonImmutable::now();

        for ($i = 0; $i < $count; $i++) {
            $next = ScheduleTrigger::nextRunAfter(['mode' => 'cron', 'cron' => $expression, 'timezone' => $timezone], $after);

            if ($next === null) {
                break;
            }

            $runs[] = $next->setTimezone($timezone);
            $after = $next;
        }

        return $runs;
    }

    /**
     * The minute and hour in words, and whether they name fixed times of day
     * (which then need "every day" when no days are given).
     *
     * @return array{0: string, 1: bool}|null
     */
    private static function timePhrase(string $minute, string $hour): ?array
    {
        $everyMinutes = self::step($minute);

        if (self::isAny($minute) && self::isAny($hour)) {
            return [__('nodes.cron.every_minute'), false];
        }

        if ($everyMinutes !== null && self::isAny($hour)) {
            return [__('nodes.cron.every_n_minutes', ['count' => $everyMinutes]), false];
        }

        if ((self::isAny($minute) || $everyMinutes !== null) && ($hours = self::hourSpan($hour)) !== null) {
            $span = __('nodes.cron.from_to', ['start' => self::time($hours[0], 0), 'end' => self::time($hours[1], 59)]);
            $every = $everyMinutes === null ? __('nodes.cron.every_minute') : __('nodes.cron.every_n_minutes', ['count' => $everyMinutes]);

            return [$every.' '.$span, false];
        }

        $minutes = self::numbers($minute, 0, 59);

        if ($minutes === null) {
            return null;
        }

        if (self::isAny($hour)) {
            return $minutes === [0]
                ? [__('nodes.cron.every_hour'), false]
                : [__('nodes.cron.at_minutes_past_every_hour', ['minutes' => self::join($minutes)]), false];
        }

        if (($everyHours = self::step($hour)) !== null && count($minutes) === 1) {
            return $minutes === [0]
                ? [__('nodes.cron.every_n_hours', ['count' => $everyHours]), false]
                : [__('nodes.cron.at_minute_past_every_n_hours', ['count' => $everyHours, 'minute' => $minutes[0]]), false];
        }

        if (count($minutes) === 1 && preg_match('/^(\d+)-(\d+)$/', $hour, $range) && (int) $range[1] < (int) $range[2] && (int) $range[2] <= 23) {
            return [__('nodes.cron.every_hour_from_to', ['start' => self::time((int) $range[1], $minutes[0]), 'end' => self::time((int) $range[2], $minutes[0])]), false];
        }

        $hours = self::numbers($hour, 0, 23);

        if ($hours === null || count($hours) * count($minutes) > 6) {
            return null;
        }

        $times = [];

        foreach ($hours as $h) {
            foreach ($minutes as $m) {
                $times[] = self::time($h, $m);
            }
        }

        return [__('nodes.cron.at_times', ['times' => self::join($times)]), true];
    }

    /**
     * The days and the months in words, each null when it is every one, or
     * false when they cannot be put into words.
     *
     * @return array{0: ?string, 1: ?string}|false
     */
    private static function dayPhrase(string $dayOfMonth, string $dayOfWeek, string $month): array|false
    {
        $months = self::isAny($month) ? [] : self::numbers($month, 1, 12);
        $daysOfMonth = self::isAny($dayOfMonth) ? [] : self::numbers($dayOfMonth, 1, 31);
        $daysOfWeek = self::isAny($dayOfWeek) ? [] : self::numbers($dayOfWeek, 0, 7);

        if ($months === null || $daysOfMonth === null || $daysOfWeek === null || ($daysOfMonth !== [] && $daysOfWeek !== [])) {
            return false;
        }

        $daysOfWeek = array_values(array_unique(array_map(fn (int $day) => $day % 7, $daysOfWeek)));
        sort($daysOfWeek);
        $daysOfWeek = count($daysOfWeek) === 7 ? [] : $daysOfWeek;
        $months = count($months) === 12 ? [] : $months;

        if (count($months) === 1 && count($daysOfMonth) === 1) {
            return [self::phrase('on_month_day', ['month' => self::monthName($months[0]), 'day' => $daysOfMonth[0]]), null];
        }

        $days = match (true) {
            $daysOfWeek === [1, 2, 3, 4, 5] => self::phrase('on_weekdays'),
            $daysOfWeek === [0, 6] => self::phrase('on_weekends'),
            $daysOfWeek !== [] => self::phrase('on_days', ['days' => self::join(array_map(self::dayName(...), $daysOfWeek))]),
            $daysOfMonth !== [] => trans_choice('nodes.cron.on_day_of_the_month', count($daysOfMonth), ['days' => self::join($daysOfMonth)]),
            default => null,
        };

        $months = $months === [] ? null : self::phrase('in_months', ['months' => self::join(array_map(self::monthName(...), $months))]);

        return [$days, $months];
    }

    /**
     * A phrase from lang/en/nodes.php's cron section, or its key if the
     * line is missing, as __() does.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function phrase(string $key, array $replace = []): string
    {
        $line = __('nodes.cron.'.$key, $replace);

        return is_string($line) ? $line : 'nodes.cron.'.$key;
    }

    private static function isAny(string $field): bool
    {
        return in_array($field, ['*', '?', '*/1'], true);
    }

    /**
     * N for a field of the form * /N (every N), else null.
     */
    private static function step(string $field): ?int
    {
        return preg_match('/^\*\/(\d+)$/', $field, $match) && (int) $match[1] > 1 ? (int) $match[1] : null;
    }

    /**
     * The first and last hour of an hour field that is one hour or one
     * range of hours, else null.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function hourSpan(string $hour): ?array
    {
        if (ctype_digit($hour) && (int) $hour <= 23) {
            return [(int) $hour, (int) $hour];
        }

        if (preg_match('/^(\d+)-(\d+)$/', $hour, $range) && (int) $range[1] < (int) $range[2] && (int) $range[2] <= 23) {
            return [(int) $range[1], (int) $range[2]];
        }

        return null;
    }

    /**
     * The values of a field made of numbers and ranges, such as 1,3-5, in
     * order; null when it has steps or anything else.
     *
     * @return list<int>|null
     */
    private static function numbers(string $field, int $min, int $max): ?array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            if (ctype_digit($part)) {
                $values[] = (int) $part;
            } elseif (preg_match('/^(\d+)-(\d+)$/', $part, $range) && (int) $range[1] <= (int) $range[2]) {
                array_push($values, ...range((int) $range[1], (int) $range[2]));
            } else {
                return null;
            }
        }

        foreach ($values as $value) {
            if ($value < $min || $value > $max) {
                return null;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private static function time(int $hour, int $minute): string
    {
        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * The name of a day of the week, 0 being Sunday, in the app's language.
     */
    private static function dayName(int $day): string
    {
        return CarbonImmutable::createFromDate(2024, 1, 7)->addDays($day)->translatedFormat('l');
    }

    private static function monthName(int $month): string
    {
        return CarbonImmutable::createFromDate(2024, $month, 1)->translatedFormat('F');
    }

    /**
     * Join items as a list in words, such as "1, 15 and 28".
     *
     * @param  list<int|string>  $items
     */
    private static function join(array $items): string
    {
        return collect($items)->map(fn (int|string $item): string => (string) $item)->join(', ', __('nodes.cron.and'));
    }
}
