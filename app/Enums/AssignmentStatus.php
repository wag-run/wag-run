<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Submitted = 'submitted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting on you'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Submitted => __('Submitted'),
            self::Expired => __('Expired'),
            self::Cancelled => __('Withdrawn'),
        };
    }

    /**
     * The Flux badge color for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved, self::Submitted => 'green',
            self::Rejected => 'red',
            self::Expired, self::Cancelled => 'zinc',
        };
    }
}
