<?php

namespace Tests\Feature\Support;

use App\Support\CronDescription;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CronDescriptionTest extends TestCase
{
    #[TestWith(['* * * * *', 'Every minute'])]
    #[TestWith(['*/15 * * * *', 'Every 15 minutes'])]
    #[TestWith(['0 * * * *', 'Every hour'])]
    #[TestWith(['30 * * * *', 'At 30 minutes past every hour'])]
    #[TestWith(['0 */2 * * *', 'Every 2 hours'])]
    #[TestWith(['0 9 * * *', 'Every day at 09:00'])]
    #[TestWith(['0 9 * * 1-5', 'At 09:00 on weekdays'])]
    #[TestWith(['0 10 * * sat,sun', 'At 10:00 on weekends'])]
    #[TestWith(['30 8 * * MON,WED', 'At 08:30 on Monday and Wednesday'])]
    #[TestWith(['0 9,13,17 * * *', 'Every day at 09:00, 13:00 and 17:00'])]
    #[TestWith(['*/10 9-17 * * 1-5', 'Every 10 minutes from 09:00 to 17:59 on weekdays'])]
    #[TestWith(['0 0 1,15 * *', 'At 00:00 on days 1 and 15 of the month'])]
    #[TestWith(['0 12 * 6-8 *', 'Every day at 12:00 in June, July and August'])]
    #[TestWith(['@yearly', 'At 00:00 on January 1'])]
    #[TestWith(['@weekly', 'At 00:00 on Sunday'])]
    public function test_puts_common_schedules_into_words(string $expression, string $description): void
    {
        $this->assertSame($description, CronDescription::describe($expression));
    }

    #[TestWith(['not a schedule'])]
    #[TestWith(['0 9 * *'])]
    #[TestWith(['0 9 1 * 1'])]
    #[TestWith(['0 9 L * *'])]
    public function test_gives_no_description_for_invalid_or_unusual_expressions(string $expression): void
    {
        $this->assertNull(CronDescription::describe($expression));
    }

    public function test_lists_the_next_runs_in_the_time_zone(): void
    {
        $runs = CronDescription::nextRuns('0 9 * * 1-5', 'America/New_York', 3, CarbonImmutable::parse('2026-11-27 15:00', 'UTC'));

        $this->assertSame(
            ['2026-11-30 09:00 America/New_York', '2026-12-01 09:00 America/New_York', '2026-12-02 09:00 America/New_York'],
            array_map(fn (CarbonImmutable $run) => $run->format('Y-m-d H:i e'), $runs),
        );
    }

    public function test_lists_no_runs_for_an_invalid_expression_or_time_zone(): void
    {
        $this->assertSame([], CronDescription::nextRuns('nope', 'UTC'));
        $this->assertSame([], CronDescription::nextRuns('0 9 * * *', 'Mars/Olympus'));
    }
}
