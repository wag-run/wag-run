<?php

namespace App\Enums;

enum ResumeReason: string
{
    case TimerElapsed = 'timer_elapsed';
    case External = 'external';
    case ChildCompleted = 'child_completed';
    case ChildFailed = 'child_failed';

    /** A pass of a loop that repeats (NodeResult::repeat) has finished. */
    case PassFinished = 'pass_finished';
}
