<?php

namespace App\Enums;

enum NodeKind: string
{
    case Trigger = 'trigger';
    case Action = 'action';

    /**
     * Sits on the canvas without running, such as a sticky note.
     */
    case Note = 'note';
}
