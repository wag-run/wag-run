<?php

namespace App\Enums;

/**
 * What a waiting step is parked on.
 */
enum WaitType: string
{
    /** A timer: resumed by the sweep once resume_at passes. */
    case Timer = 'timer';

    /** An outside response such as an Approval or Task; resume_at is its timeout. */
    case External = 'external';

    /** A sync child workflow. */
    case Child = 'child';

    /** A For loop running its body for each item. */
    case Loop = 'loop';

    /** A Merge collecting its incoming branches. */
    case Join = 'join';
}
