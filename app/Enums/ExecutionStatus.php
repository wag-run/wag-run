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
