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
            self::Pending => __('common.waiting_on_you'),
            self::Approved => __('enums.assignment_status.approved'),
            self::Rejected => __('enums.assignment_status.rejected'),
            self::Submitted => __('enums.assignment_status.submitted'),
            self::Expired => __('enums.assignment_status.expired'),
            self::Cancelled => __('enums.assignment_status.withdrawn'),
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
