<?php

namespace App\Enums;

enum ResumeReason: string
{
    case TimerElapsed = 'timer_elapsed';
    case External = 'external';
    case ChildCompleted = 'child_completed';
    case ChildFailed = 'child_failed';
}
