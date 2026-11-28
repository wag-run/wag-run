<?php

namespace App\Enums;

/**
 * Which of a run's data a set of masked values was taken from, and so
 * where the engine puts them back.
 */
enum MaskedSlot: string
{
    /** The run's trigger data (executions.input), read as trigger. */
    case Trigger = 'trigger';

    /** A step's output, read as nodes.<id>.output. */
    case Output = 'output';

    /** A waiting step's state, such as a For loop's items, read as item. */
    case State = 'state';

    /** The run's workflow variables, read as vars.<name>; paths start with the name. */
    case Variable = 'variable';
}
