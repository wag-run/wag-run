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
}
