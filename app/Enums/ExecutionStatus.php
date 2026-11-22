<?php

namespace App\Enums;

enum ExecutionStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Complete = 'complete';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Running => __('common.running'),
            self::Waiting => __('common.waiting'),
            self::Complete => __('common.complete'),
            self::Failed => __('common.failed'),
            self::Cancelled => __('common.cancelled'),
            self::Skipped => __('enums.execution_status.skipped'),
        };
    }

    /**
     * The Flux badge color for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Running => 'sky',
            self::Waiting => 'amber',
            self::Complete => 'green',
            self::Failed => 'red',
            self::Cancelled, self::Skipped => 'zinc',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Complete, self::Failed, self::Cancelled, self::Skipped], true);
    }

    /**
     * Statuses that count as "in progress" for the workflow's concurrency setting.
     *
     * @return list<self>
     */
    public static function inProgress(): array
    {
        return [self::Running, self::Waiting];
    }
}
