<?php

namespace App\Enums;

enum AssignmentType: string
{
    case Approval = 'approval';
    case Task = 'task';

    public function label(): string
    {
        return match ($this) {
            self::Approval => __('common.approval'),
            self::Task => __('common.task'),
        };
    }
}
