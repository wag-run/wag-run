<?php

namespace App\Enums;

enum StepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Complete = 'complete';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Running, self::Waiting];
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running, self::Cancelled],
            self::Running => [self::Complete, self::Failed, self::Waiting, self::Cancelled],
            self::Waiting => [self::Complete, self::Failed, self::Cancelled, self::Running, self::Pending],
            self::Complete, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
